<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\SubArea;
use App\Services\FuncLocSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FunctionalLocationController extends Controller
{
    protected FuncLocSyncService $funcLocSyncService;

    public function __construct(FuncLocSyncService $funcLocSyncService)
    {
        $this->funcLocSyncService = $funcLocSyncService;
    }

    /**
     * Tampilkan daftar Functional Location.
     *
     * Jika tidak ada filter yang aktif, data ditampilkan sebagai pohon hierarki
     * (collapsible per level). Jika ada filter (level/parent/status), data
     * ditampilkan sebagai daftar datar agar hasil filter mudah dibaca.
     *
     * @param  Request  $request
     * @return View
     */
    public function index(Request $request): View
    {
        $filters = [
            'level'     => $request->query('level'),
            'parent_id' => $request->query('parent_id'),
            'status'    => $request->query('status'),
        ];

        $hasFilter = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();

        // Muat seluruh node sekaligus (lengkap dengan jumlah asset & laporan
        // langsung) supaya jumlah kumulatif per node bisa dihitung dalam satu
        // lintasan.
        $all    = FunctionalLocation::withCount(['assets', 'reports'])->orderBy('code')->get();
        $totals = $this->attachSubtreeTotals($all);

        $tree  = null;
        $items = null;

        if ($hasFilter) {
            $query = FunctionalLocation::withCount('assets');

            if ($filters['level'] !== null && $filters['level'] !== '') {
                $query->ofLevel((int) $filters['level']);
            }

            if ($filters['parent_id'] !== null && $filters['parent_id'] !== '') {
                $query->underParent((int) $filters['parent_id']);
            }

            if ($filters['status'] !== null && $filters['status'] !== '') {
                $query->where('is_active', $filters['status'] === 'active');
            }

            $items = $query->orderBy('code')->get();

            // Pada tampilan datar hasil filter, child tidak ikut dimuat,
            // sehingga jumlah kumulatif disalin dari peta totals yang sudah
            // dihitung dari seluruh node.
            $items->each(function (FunctionalLocation $node) use ($totals) {
                $node->setAttribute('assets_total', $totals[$node->id]['assets'] ?? (int) $node->assets_count);
                $node->setAttribute('reports_total', $totals[$node->id]['reports'] ?? (int) $node->reports_count);
            });
        } else {
            $tree = $this->buildTree($all);
        }

        $parentOptions = FunctionalLocation::active()->orderBy('code')->get(['id', 'code', 'level']);

        $stats = [
            'total'         => FunctionalLocation::count(),
            'active'        => FunctionalLocation::active()->count(),
            'inactive'      => FunctionalLocation::where('is_active', false)->count(),
            'without_asset' => $all->filter(fn (FunctionalLocation $node) => ($totals[$node->id]['assets'] ?? 0) === 0)->count(),
        ];

        return view('func-locs.index', [
            'tree'          => $tree,
            'items'         => $items,
            'parentOptions' => $parentOptions,
            'stats'         => $stats,
            'filters'       => $filters,
            'levelLabels'   => $this->levelLabels(),
        ]);
    }

    /**
     * Tampilkan form tambah node FuncLoc baru.
     *
     * @param  Request  $request
     * @return View
     */
    public function create(Request $request): View
    {
        // Dropdown hanya menampilkan parent yang bisa memiliki anak (level
        // di bawah Section). Node dengan level tak dikenal (mis. 4 dari data
        // lama) otomatis disembunyikan agar tidak memicu error saat label
        // levelnya diakses.
        $parentOptions = FunctionalLocation::active()
            ->where('level', '<', FunctionalLocation::LEVEL_SECTION)
            ->orderBy('code')
            ->get(['id', 'code', 'level']);

        $selectedParentId = $request->query('parent_id');

        return view('func-locs.create', [
            'parentOptions'    => $parentOptions,
            'selectedParentId' => $selectedParentId,
            'levelLabels'      => $this->levelLabels(),
        ]);
    }

    /**
     * Simpan satu atau beberapa node FuncLoc baru sekaligus di bawah parent
     * yang sama. Seluruh baris disimpan dalam satu transaksi database
     * (all-or-nothing) sehingga tidak ada node yang tersimpan setengah jalan
     * jika salah satu baris gagal.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'parent_id'           => ['nullable', 'exists:functional_locations,id'],
            'children'            => ['required', 'array', 'min:1'],
            'children.*.segment'  => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'children.*.name'     => ['required', 'string', 'max:255'],
        ]);

        $parent = null;

        if (! empty($validated['parent_id'])) {
            $parent = FunctionalLocation::findOrFail($validated['parent_id']);
        }

        if ($parent !== null && $parent->level >= FunctionalLocation::LEVEL_SECTION) {
            return back()
                ->withInput()
                ->withErrors(['parent_id' => 'Node Level Section (L3) tidak bisa memiliki anak.']);
        }

        // Normalisasi segment tiap baris (uppercase + trim) sebelum dicek duplikat,
        // supaya konsisten dengan format yang dipakai saat menyimpan.
        $rows = collect($validated['children'])->map(fn (array $row) => [
            'segment' => strtoupper(trim($row['segment'])),
            'name'    => $row['name'],
        ])->values();

        $errors = $this->validateChildRows($rows, $parent);

        if ($errors->isNotEmpty()) {
            return back()
                ->withInput()
                ->withErrors($errors->toArray());
        }

        $createdCodes = DB::transaction(function () use ($rows, $parent) {
            return $rows->map(function (array $row) use ($parent) {
                $code = FunctionalLocation::buildCode($parent, $row['segment']);

                FunctionalLocation::create([
                    'code'      => $code,
                    'segment'   => $row['segment'],
                    'name'      => $row['name'],
                    'level'     => $parent !== null ? $parent->level + 1 : FunctionalLocation::LEVEL_SITE,
                    'parent_id' => $parent?->id,
                    'is_active' => true,
                ]);

                return $code;
            });
        });

        $count = $createdCodes->count();

        $message = $count > 1
            ? "{$count} Functional Location berhasil dibuat."
            : 'Functional Location "' . $createdCodes->first() . '" berhasil dibuat.';

        return redirect()
            ->route('func-locs.index')
            ->with('success', $message);
    }

    /**
     * Tampilkan form edit node FuncLoc.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return View
     */
    public function edit(FunctionalLocation $funcLoc): View
    {
        return view('func-locs.edit', [
            'funcLoc'     => $funcLoc,
            'levelLabels' => $this->levelLabels(),
        ]);
    }

    /**
     * Perbarui name dan is_active dari node FuncLoc. Kode (full path) tidak
     * dapat diubah di sini — perubahan kode hanya terjadi lewat fitur Pindah
     * (move) yang menulis ulang kode seluruh subtree secara konsisten.
     *
     * @param  Request  $request
     * @param  FunctionalLocation  $funcLoc
     * @return RedirectResponse
     */
    public function update(Request $request, FunctionalLocation $funcLoc): RedirectResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $wantsInactive = ! $request->boolean('is_active');

        if ($wantsInactive && $this->hasActiveChildren($funcLoc)) {
            return back()
                ->withInput()
                ->withErrors(['is_active' => 'Node ini masih punya child aktif. Nonaktifkan child terlebih dahulu.']);
        }

        $funcLoc->update([
            'name'      => $validated['name'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('func-locs.index')
            ->with('success', 'Functional Location "' . $funcLoc->code . '" berhasil diperbarui.');
    }

    /**
     * Nonaktifkan node FuncLoc (soft-deactivate). Data master ini tidak
     * dihapus permanen karena masih dirujuk oleh asset/report/histori.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return RedirectResponse
     */
    public function destroy(FunctionalLocation $funcLoc): RedirectResponse
    {
        if ($this->hasActiveChildren($funcLoc)) {
            return back()->with('error', 'Node ini masih punya child aktif. Nonaktifkan child terlebih dahulu.');
        }

        $funcLoc->update(['is_active' => false]);

        return redirect()
            ->route('func-locs.index')
            ->with('success', 'Functional Location "' . $funcLoc->code . '" berhasil dinonaktifkan.');
    }

    /**
     * Hapus permanen node FuncLoc yang sudah NONAKTIF beserta seluruh
     * subtree-nya. Node yang masih aktif ditolak. Node (atau keturunannya)
     * yang masih dipakai asset ditolak karena constraint database
     * assets.funcloc_id (NOT NULL + ON DELETE RESTRICT).
     *
     * Report, Area, dan SubArea yang menunjuk ke node yang dihapus otomatis
     * ke-null-kan oleh foreign key ON DELETE SET NULL di level database.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return RedirectResponse
     */
    public function forceDestroy(FunctionalLocation $funcLoc): RedirectResponse
    {
        if ($funcLoc->is_active) {
            return back()->with('error', 'Hanya node berstatus Nonaktif yang bisa dihapus permanen.');
        }

        $subtreeIds = $funcLoc->getSubtreeIds();

        $activeChildCount = FunctionalLocation::whereIn('parent_id', $subtreeIds)
            ->where('is_active', true)
            ->count();

        if ($activeChildCount > 0) {
            return back()->with('error', 'Masih ada child aktif di dalam subtree node ini. Nonaktifkan child terlebih dahulu.');
        }

        $assetCount = \App\Models\Asset::whereIn('funcloc_id', $subtreeIds)->count();

        if ($assetCount > 0) {
            return back()->with('error', "Node \"{$funcLoc->code}\" atau keturunannya masih dipakai {$assetCount} asset. Pindahkan asset terlebih dahulu sebelum menghapus.");
        }

        DB::transaction(function () use ($subtreeIds) {
            FunctionalLocation::whereIn('id', $subtreeIds)->delete();
        });

        return redirect()
            ->route('func-locs.index')
            ->with('success', 'Functional Location "' . $funcLoc->code . '" beserta seluruh subtree-nya berhasil dihapus permanen.');
    }

    /**
     * Tampilkan form pindah node FuncLoc ke parent lain. Seluruh subtree ikut
     * berpindah dan kode (full path) setiap node di subtree ditulis ulang
     * mengikuti jalur baru.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return View
     */
    public function move(FunctionalLocation $funcLoc): View
    {
        $excludedIds = $funcLoc->getSubtreeIds();

        // Parent tujuan harus aktif, bukan level Section (L3), dan bukan
        // bagian dari subtree node itu sendiri (cegah cycle).
        $parentOptions = FunctionalLocation::active()
            ->where('level', '<', FunctionalLocation::LEVEL_SECTION)
            ->whereNotIn('id', $excludedIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level']);

        return view('func-locs.move', [
            'funcLoc'       => $funcLoc,
            'parentOptions' => $parentOptions,
            'levelLabels'   => $this->levelLabels(),
        ]);
    }

    /**
     * Proses pemindahan node FuncLoc (beserta subtree) ke parent tujuan.
     *
     * Setelah parent_id berubah:
     * - level setiap node di subtree dihitung ulang mengikuti kedalaman baru;
     * - code (full path) setiap node di subtree ditulis ulang mengikuti jalur
     *   baru, dengan segment lama dipertahankan;
     * - relasi master lama (company_id, department_id, area_id, sub_area_id)
     *   disinkronkan ulang mengikuti parent baru agar konsisten.
     *
     * Semua perubahan dijalankan dalam satu transaksi database (all-or-nothing).
     *
     * @param  Request  $request
     * @param  FunctionalLocation  $funcLoc
     * @return RedirectResponse
     */
    public function moveUpdate(Request $request, FunctionalLocation $funcLoc): RedirectResponse
    {
        $validated = $request->validate([
            'new_parent_id' => ['nullable', 'exists:functional_locations,id'],
        ]);

        $newParentId = ! empty($validated['new_parent_id'])
            ? (int) $validated['new_parent_id']
            : null;

        $newParent = $newParentId !== null
            ? FunctionalLocation::findOrFail($newParentId)
            : null;

        $validationFailed = $this->validateMoveTarget($funcLoc, $newParent);

        if ($validationFailed !== null) {
            return back()
                ->withInput()
                ->withErrors(['new_parent_id' => $validationFailed]);
        }

        $message = DB::transaction(function () use ($funcLoc, $newParent) {
            $newLevel = $newParent !== null
                ? $newParent->level + 1
                : FunctionalLocation::LEVEL_SITE;

            $newCode = FunctionalLocation::buildCode($newParent, $funcLoc->segment);

            // Pindahkan node itu sendiri tanpa memicu observer (sinkronisasi
            // master lama ditangani manual di akhir transaksi).
            $funcLoc->forceFill([
                'parent_id' => $newParent?->id,
                'level'     => $newLevel,
                'code'      => $newCode,
            ])->saveQuietly();

            $this->rewriteDescendantCodes($funcLoc);

            // Sinkronkan master lama untuk seluruh subtree setelah jalur baru
            // terbentuk, diproses dari level terendah (parent) ke tertinggi
            // (child) agar relasi master parent selalu sudah siap saat child
            // disinkronkan. Reload per node agar relasi parent yang baru
            // benar-benar segar setelah parent-nya ditulis.
            $subtreeIds = $funcLoc->getSubtreeIds();
            $subtree    = FunctionalLocation::with('parent')
                ->whereIn('id', $subtreeIds)
                ->orderBy('level')
                ->get();

            foreach ($subtree as $node) {
                $freshNode = FunctionalLocation::with('parent')->findOrFail($node->id);
                $this->syncMasterForNode($freshNode);
            }

            return "Functional Location \"{$funcLoc->code}\" berhasil dipindahkan beserta seluruh subtree-nya.";
        });

        return redirect()
            ->route('func-locs.index')
            ->with('success', $message);
    }

    /**
     * Validasi target pemindahan node FuncLoc.
     *
     * @param  FunctionalLocation  $funcLoc
     * @param  FunctionalLocation|null  $newParent
     * @return string|null  Pesan error, atau null jika valid.
     */
    private function validateMoveTarget(FunctionalLocation $funcLoc, ?FunctionalLocation $newParent): ?string
    {
        if ($newParent === null) {
            return null;
        }

        if ($newParent->id === $funcLoc->id) {
            return 'Parent tujuan tidak boleh sama dengan node itu sendiri.';
        }

        if (! $newParent->is_active) {
            return 'Parent tujuan harus berstatus Aktif.';
        }

        if ($newParent->level >= FunctionalLocation::LEVEL_SECTION) {
            return 'Node Level Section (L3) tidak bisa memiliki anak.';
        }

        if (in_array($newParent->id, $funcLoc->getDescendantIds(), true)) {
            return 'Parent tujuan tidak boleh berada di dalam subtree node ini (mencegah siklus).';
        }

        $segmentTaken = FunctionalLocation::where('parent_id', $newParent->id)
            ->where('segment', $funcLoc->segment)
            ->where('id', '!=', $funcLoc->id)
            ->exists();

        if ($segmentTaken) {
            return 'Segment "' . $funcLoc->segment . '" sudah dipakai oleh node lain di bawah parent tujuan.';
        }

        return null;
    }

    /**
     * Tulis ulang code dan level seluruh keturunan (descendant) dari node yang
     * baru dipindah, secara top-down, tanpa memicu observer.
     *
     * @param  FunctionalLocation  $funcLoc  Node yang sudah dipindah (parent_id/level/code baru).
     * @return void
     */
    private function rewriteDescendantCodes(FunctionalLocation $funcLoc): void
    {
        $byParent = FunctionalLocation::whereIn('id', $funcLoc->getDescendantIds())
            ->get(['id', 'code', 'segment', 'level', 'parent_id'])
            ->groupBy('parent_id');

        // Tumpukan berisi [parentId, parentLevel, parentCode]. Proses dari
        // node yang dipindah ke bawah; child meneruskan level & code parent.
        $stack = [[$funcLoc->id, $funcLoc->level, $funcLoc->code]];

        while ($stack !== []) {
            [$parentId, $parentLevel, $parentCode] = array_pop($stack);

            $children = $byParent->get($parentId, collect());

            foreach ($children as $child) {
                $childLevel = $parentLevel + 1;
                $childCode  = $parentCode . '-' . $child->segment;

                FunctionalLocation::where('id', $child->id)->update([
                    'level' => $childLevel,
                    'code'  => $childCode,
                ]);

                $stack[] = [$child->id, $childLevel, $childCode];
            }
        }
    }

    /**
     * Sinkronkan relasi master lama (Company/Department/Area/SubArea) sebuah
     * node FuncLoc mengikuti parent barunya. Dipakai setelah node dipindah.
     *
     * Aturan mengikuti observer created():
     * - L0 (PT)   -> Company (firstOrCreate by code)
     * - L1 (Dept) -> Department (firstOrCreate by company_id + code)
     * - L2 (Area) -> Area tujuan dicari by department_id + code; jika sudah
     *                ada, node berpindah menunjuk ke Area tsb. Jika belum,
     *                Area dipakai yang terhubung funcloc_id node ini atau
     *                dibuat baru.
     * - L3 (Sec)  -> SubArea tujuan dicari by area_id + code; jika sudah ada,
     *                node berpindah menunjuk ke SubArea tsb. Jika belum,
     *                SubArea dipakai yang terhubung funcloc_id node ini atau
     *                dibuat baru.
     *
     * Simpan memakai saveQuietly() agar observer updated (sinkron name) tidak
     * berjalan berulang saat transaksi pemindahan.
     *
     * @param  FunctionalLocation  $node
     * @return void
     */
    private function syncMasterForNode(FunctionalLocation $node): void
    {
        if ($node->level === FunctionalLocation::LEVEL_SITE) {
            $company = Company::firstOrCreate(
                ['code' => $node->segment],
                ['name' => $node->name]
            );

            $node->forceFill([
                'company_id'    => $company->id,
                'department_id' => null,
                'area_id'       => null,
                'sub_area_id'   => null,
            ])->saveQuietly();

            return;
        }

        $parent = $node->parent;

        if ($node->level === FunctionalLocation::LEVEL_DEPARTMENT) {
            $companyId = $parent?->company_id;
            $department = $companyId !== null
                ? Department::firstOrCreate(
                    ['company_id' => $companyId, 'code' => $node->segment],
                    ['name' => $node->name]
                )
                : null;

            $node->forceFill([
                'company_id'    => $companyId,
                'department_id' => $department?->id,
                'area_id'       => null,
                'sub_area_id'   => null,
            ])->saveQuietly();

            return;
        }

        if ($node->level === FunctionalLocation::LEVEL_AREA) {
            $departmentId = $parent?->department_id;

            if ($departmentId !== null) {
                // Area dengan kode yang sama sudah ada di department tujuan:
                // node berpindah menunjuk ke Area tersebut (hindari duplikat
                // constraint unique department_id + code).
                $areaAtTarget = Area::where('department_id', $departmentId)
                    ->where('code', $node->segment)
                    ->first();

                if ($areaAtTarget !== null) {
                    $area = $areaAtTarget;

                    // Master Area tujuan sudah dipakai node FuncLoc lain.
                    // Lepaskan ikatan node tersebut agar tidak ada dua node
                    // yang menunjuk ke Area yang sama (1:1 L2 <-> Area).
                    FunctionalLocation::where('area_id', $area->id)
                        ->where('id', '!=', $node->id)
                        ->update(['area_id' => null]);
                } else {
                    $area = Area::where('funcloc_id', $node->id)->first()
                        ?? Area::create([
                            'department_id' => $departmentId,
                            'code'          => $node->segment,
                            'name'          => $node->name,
                            'funcloc_id'    => $node->id,
                        ]);

                    if ((int) $area->department_id !== $departmentId) {
                        $area->forceFill(['department_id' => $departmentId]);
                    }

                    $area->forceFill([
                        'code' => $node->segment,
                        'name' => $node->name,
                    ]);

                    if ($area->funcloc_id === null) {
                        $area->funcloc_id = $node->id;
                    }

                    if ($area->isDirty()) {
                        $area->save();
                    }
                }

                // Pastikan area yang dipakai terhubung dua arah ke node ini,
                // dan lepaskan area lain yang masih menunjuk ke node ini.
                if ($area->funcloc_id !== $node->id) {
                    $area->funcloc_id = $node->id;
                    $area->save();
                }

                Area::where('funcloc_id', $node->id)
                    ->where('id', '!=', $area->id)
                    ->update(['funcloc_id' => null]);

                $node->forceFill([
                    'company_id'    => $parent?->company_id,
                    'department_id' => $departmentId,
                    'area_id'       => $area->id,
                    'sub_area_id'   => null,
                ])->saveQuietly();
            } else {
                $node->forceFill([
                    'company_id'    => $parent?->company_id,
                    'department_id' => null,
                    'area_id'       => null,
                    'sub_area_id'   => null,
                ])->saveQuietly();
            }

            return;
        }

        if ($node->level === FunctionalLocation::LEVEL_SECTION) {
            $areaId = $parent?->area_id;

            if ($areaId !== null) {
                // SubArea dengan kode yang sama sudah ada di area tujuan:
                // node berpindah menunjuk ke SubArea tersebut (hindari
                // duplikat constraint unique area_id + code).
                $subAreaAtTarget = SubArea::where('area_id', $areaId)
                    ->where('code', $node->segment)
                    ->first();

                if ($subAreaAtTarget !== null) {
                    $subArea = $subAreaAtTarget;

                    // Master SubArea tujuan sudah dipakai node FuncLoc lain.
                    // Lepaskan ikatan node tersebut agar tidak ada dua node
                    // yang menunjuk ke SubArea yang sama (1:1 L3 <-> SubArea).
                    FunctionalLocation::where('sub_area_id', $subArea->id)
                        ->where('id', '!=', $node->id)
                        ->update(['sub_area_id' => null]);
                } else {
                    $subArea = SubArea::where('funcloc_id', $node->id)->first()
                        ?? SubArea::create([
                            'area_id'    => $areaId,
                            'code'       => $node->segment,
                            'name'       => $node->name,
                            'funcloc_id' => $node->id,
                        ]);

                    if ((int) $subArea->area_id !== $areaId) {
                        $subArea->forceFill(['area_id' => $areaId]);
                    }

                    $subArea->forceFill([
                        'code' => $node->segment,
                        'name' => $node->name,
                    ]);

                    if ($subArea->funcloc_id === null) {
                        $subArea->funcloc_id = $node->id;
                    }

                    if ($subArea->isDirty()) {
                        $subArea->save();
                    }
                }

                // Pastikan sub-area yang dipakai terhubung dua arah ke node
                // ini, dan lepaskan sub-area lain yang masih menunjuk ke node.
                if ($subArea->funcloc_id !== $node->id) {
                    $subArea->funcloc_id = $node->id;
                    $subArea->save();
                }

                SubArea::where('funcloc_id', $node->id)
                    ->where('id', '!=', $subArea->id)
                    ->update(['funcloc_id' => null]);

                $node->forceFill([
                    'company_id'    => $parent?->company_id,
                    'department_id' => $parent?->department_id,
                    'area_id'       => $areaId,
                    'sub_area_id'   => $subArea->id,
                ])->saveQuietly();
            } else {
                $node->forceFill([
                    'company_id'    => $parent?->company_id,
                    'department_id' => $parent?->department_id,
                    'area_id'       => null,
                    'sub_area_id'   => null,
                ])->saveQuietly();
            }
        }
    }

    /**
     * Analisis assets.functional_loc untuk preview sinkronisasi: node baru
     * yang akan dibuat, asset yang akan ditautkan, dan kode yang tidak valid.
     * Tidak ada perubahan yang disimpan ke database pada tahap ini.
     *
     * @return JsonResponse
     */
    public function previewSync(): JsonResponse
    {
        try {
            $analysis = $this->funcLocSyncService->analyze();

            return response()->json([
                'success'  => true,
                'analysis' => $analysis,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menganalisa data: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Eksekusi sinkronisasi berdasarkan hasil previewSync(): buat node
     * FunctionalLocation yang belum ada dan tautkan funcloc_id pada asset.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function executeSync(Request $request): JsonResponse
    {
        $request->validate([
            'analysis' => ['required', 'array'],
        ]);

        try {
            $result = $this->funcLocSyncService->execute($request->input('analysis'));

            return response()->json(array_merge($result, [
                'message' => "Sinkronisasi berhasil. {$result['node_created_count']} FuncLoc baru dibuat, {$result['asset_linked_count']} asset ditautkan.",
            ]));
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal sinkronisasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cari node FuncLoc aktif berdasarkan kode atau nama, dipakai oleh
     * komponen picker (<x-funcloc-picker>) di form web seperti Create/Edit
     * Asset. Hasil dibatasi 20 baris agar respons tetap ringan untuk
     * pencarian ketik-cepat (debounced) di sisi frontend.
     *
     * Setiap hasil menyertakan 'ancestors' (jalur leluhur dari root sampai
     * satu level di atas node) agar frontend bisa auto-expand tree picker
     * langsung menuju node yang cocok tanpa query tambahan per node.
     * Relasi parent di-eager-load berjenjang (maksimal 3 tingkat, sesuai
     * kedalaman hierarki LEVEL_SITE..LEVEL_SECTION) untuk menghindari N+1
     * query saat getAncestors() dipanggil di dalam map().
     *
     * @param  Request  $request  Query: q (kata kunci), level (opsional, filter level)
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $term  = (string) $request->query('q', '');
        $level = $request->query('level');

        $query = FunctionalLocation::active()
            ->with('parent.parent.parent')
            ->search($term);

        if ($level !== null && $level !== '') {
            $query->ofLevel((int) $level);
        }

        $results = $query
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'name', 'level', 'parent_id'])
            ->map(fn (FunctionalLocation $node) => [
                'id'          => $node->id,
                'code'        => $node->code,
                'name'        => $node->name,
                'level'       => $node->level,
                'level_label' => $node->level_label,
                'label'       => $node->code . ' — ' . $node->name,
                'ancestors'   => $this->mapAncestors($node),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Ambil daftar node aktif satu level di bawah parent tertentu, dipakai
     * oleh picker hierarki (<x-funcloc-picker>) di form web untuk memuat
     * satu level secara lazy setiap kali node di-expand. Jika parent_id
     * tidak dikirim, dikembalikan node root (level PT / LEVEL_SITE).
     *
     * Setiap node disertai 'has_children' agar frontend tahu perlu
     * menampilkan panah expand atau tidak, tanpa harus fetch dulu.
     *
     * @param  Request  $request  Query: parent_id (opsional)
     * @return JsonResponse
     */
    public function children(Request $request): JsonResponse
    {
        $parentId = $request->query('parent_id');

        $query = FunctionalLocation::active();

        if ($parentId === null || $parentId === '') {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', (int) $parentId);
        }

        $nodes = $query->orderBy('code')->get(['id', 'code', 'name', 'level', 'parent_id']);

        $childCounts = FunctionalLocation::active()
            ->whereIn('parent_id', $nodes->pluck('id'))
            ->selectRaw('parent_id, count(*) as aggregate')
            ->groupBy('parent_id')
            ->pluck('aggregate', 'parent_id');

        $results = $nodes
            ->map(fn (FunctionalLocation $node) => [
                'id'           => $node->id,
                'code'         => $node->code,
                'name'         => $node->name,
                'level'        => $node->level,
                'level_label'  => $node->level_label,
                'has_children' => (bool) ($childCounts[$node->id] ?? false),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Ubah koleksi ancestor (dari getAncestors(), urutan root ke bawah)
     * menjadi array asosiatif ringkas untuk payload JSON.
     *
     * @param  FunctionalLocation  $node
     * @return array<int, array{id: int, code: string, name: string, level: int}>
     */
    private function mapAncestors(FunctionalLocation $node): array
    {
        return $node->getAncestors()
            ->map(fn (FunctionalLocation $ancestor) => [
                'id'    => $ancestor->id,
                'code'  => $ancestor->code,
                'name'  => $ancestor->name,
                'level' => $ancestor->level,
            ])
            ->values()
            ->all();
    }

    /**
     * Cek apakah node masih memiliki child yang berstatus aktif.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return bool
     */
    private function hasActiveChildren(FunctionalLocation $funcLoc): bool
    {
        return $funcLoc->children()->active()->exists();
    }

    /**
     * Validasi duplikat segment untuk kumpulan baris children pada satu
     * submit: dicek dua arah, yaitu antar baris yang dikirim bersamaan dan
     * terhadap segment yang sudah ada di database di bawah parent yang sama.
     * Pesan error dikembalikan dengan key per baris (children.{index}.segment)
     * agar frontend bisa menunjukkan baris mana yang bermasalah.
     *
     * @param  Collection<int, array{segment: string, name: string}>  $rows
     * @param  FunctionalLocation|null  $parent
     * @return Collection<string, string>
     */
    private function validateChildRows(Collection $rows, ?FunctionalLocation $parent): Collection
    {
        $errors = collect();
        $seenInSubmit = [];

        // Cek duplikat antar baris yang dikirim dalam satu submit.
        foreach ($rows as $index => $row) {
            $segment = $row['segment'];

            if (isset($seenInSubmit[$segment])) {
                $errors->put(
                    "children.{$index}.segment",
                    'Segment "' . $segment . '" duplikat dengan baris lain pada submit ini.'
                );

                continue;
            }

            $seenInSubmit[$segment] = true;
        }

        // Cek duplikat terhadap segment yang sudah ada di database di bawah parent yang sama.
        $existingSegments = FunctionalLocation::where('parent_id', $parent?->id)
            ->whereIn('segment', $rows->pluck('segment')->unique()->values())
            ->pluck('segment')
            ->flip();

        foreach ($rows as $index => $row) {
            $key = "children.{$index}.segment";

            if ($errors->has($key)) {
                continue;
            }

            if ($existingSegments->has($row['segment'])) {
                $errors->put(
                    $key,
                    'Segment "' . $row['segment'] . '" sudah dipakai oleh node lain di bawah parent yang sama.'
                );
            }
        }

        return $errors;
    }

    /**
     * Susun koleksi flat FunctionalLocation menjadi struktur pohon
     * berdasarkan parent_id, tanpa query tambahan per node (hindari N+1).
     *
     * @param  Collection<int, FunctionalLocation>  $all
     * @return Collection<int, FunctionalLocation>
     */
    private function buildTree(Collection $all): Collection
    {
        // Normalisasi key null menjadi string 'root', karena PHP mengubah
        // array key null menjadi string kosong pada groupBy() bawaan Laravel.
        $grouped = $all->groupBy(fn (FunctionalLocation $node) => $node->parent_id ?? 'root');

        $attach = function ($parentKey) use (&$attach, $grouped) {
            return ($grouped->get($parentKey) ?? collect())->map(function (FunctionalLocation $node) use ($attach) {
                $node->setAttribute('child_nodes', $attach($node->id));

                return $node;
            })->values();
        };

        return $attach('root');
    }

    /**
     * Hitung jumlah asset & laporan kumulatif untuk setiap node (yang menempel
     * langsung pada node ditambah seluruh keturunannya), lalu simpan hasilnya
     * sebagai atribut `assets_total` dan `reports_total` pada model yang sama.
     *
     * Dipakai agar angka asset/laporan pada node induk (misal level PT) tidak
     * tampil 0 hanya karena tidak ada baris yang funcloc_id-nya persis menunjuk
     * ke node tersebut, padahal di bawahnya banyak.
     *
     * @param  Collection<int, FunctionalLocation>  $all
     * @return array<int, array{assets: int, reports: int}>  Total per ID node
     */
    private function attachSubtreeTotals(Collection $all): array
    {
        $grouped = $all->groupBy(fn (FunctionalLocation $node) => $node->parent_id ?? 'root');
        $laporan = $this->laporanLangsungPerNode();
        $totals  = [];

        $sum = function ($parentKey) use (&$sum, $grouped, $laporan, &$totals) {
            $subtotal = ['assets' => 0, 'reports' => 0];

            foreach ($grouped->get($parentKey) ?? collect() as $node) {
                $anak = $sum($node->id);

                $aset    = (int) $node->assets_count + $anak['assets'];
                $laporanNode = ($laporan[$node->id] ?? 0) + $anak['reports'];

                $node->setAttribute('assets_total', $aset);
                $node->setAttribute('reports_total', $laporanNode);

                $totals[$node->id] = ['assets' => $aset, 'reports' => $laporanNode];

                $subtotal['assets'] += $aset;
                $subtotal['reports'] += $laporanNode;
            }

            return $subtotal;
        };

        $sum('root');

        return $totals;
    }

    /**
     * Hitung jumlah laporan LANGSUNG per node (bukan kumulatif): laporan yang
     * lokasinya node itu, atau laporan yang alatnya berlokasi di node itu.
     *
     * Satu laporan hanya dihitung satu kali per node (walau lokasi laporan dan
     * lokasi alatnya sama) supaya angkanya sama dengan daftar laporan pada
     * halaman detail lokasi.
     *
     * @return array<int, int>  Jumlah laporan per ID node
     */
    private function laporanLangsungPerNode(): array
    {
        $jumlah = [];

        Report::query()
            ->leftJoin('assets', 'assets.id', '=', 'reports.asset_id')
            ->where(function ($q) {
                $q->whereNotNull('reports.funcloc_id')
                    ->orWhereNotNull('assets.funcloc_id');
            })
            ->get(['reports.funcloc_id', 'assets.funcloc_id as funcloc_aset'])
            ->each(function ($baris) use (&$jumlah) {
                $node = [];

                if ($baris->funcloc_id !== null) {
                    $node[] = (int) $baris->funcloc_id;
                }

                if ($baris->funcloc_aset !== null) {
                    $node[] = (int) $baris->funcloc_aset;
                }

                foreach (array_unique($node) as $id) {
                    $jumlah[$id] = ($jumlah[$id] ?? 0) + 1;
                }
            });

        return $jumlah;
    }

    /**
     * Label level dalam Bahasa Indonesia, dipakai untuk dropdown filter/form.
     *
     * @return array<int, string>
     */
    private function levelLabels(): array
    {
        return [
            FunctionalLocation::LEVEL_SITE       => 'PT',
            FunctionalLocation::LEVEL_DEPARTMENT => 'Departemen',
            FunctionalLocation::LEVEL_AREA       => 'Area',
            FunctionalLocation::LEVEL_SECTION    => 'Section',
        ];
    }
}
