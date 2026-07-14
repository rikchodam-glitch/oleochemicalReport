<?php

namespace App\Http\Controllers;

use App\Models\FunctionalLocation;
use App\Services\FuncLocSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        } else {
            $all  = FunctionalLocation::withCount('assets')->orderBy('code')->get();
            $tree = $this->buildTree($all);
        }

        $parentOptions = FunctionalLocation::active()->orderBy('code')->get(['id', 'code', 'level']);

        $stats = [
            'total'         => FunctionalLocation::count(),
            'active'        => FunctionalLocation::active()->count(),
            'inactive'      => FunctionalLocation::where('is_active', false)->count(),
            'without_asset' => FunctionalLocation::doesntHave('assets')->count(),
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
        $parentOptions = FunctionalLocation::active()->orderBy('code')->get(['id', 'code', 'level']);
        $selectedParentId = $request->query('parent_id');

        return view('func-locs.create', [
            'parentOptions'    => $parentOptions,
            'selectedParentId' => $selectedParentId,
            'levelLabels'      => $this->levelLabels(),
        ]);
    }

    /**
     * Simpan node FuncLoc baru.
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:functional_locations,id'],
            'segment'   => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name'      => ['required', 'string', 'max:255'],
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

        $normalizedSegment = strtoupper(trim($validated['segment']));

        $duplicateExists = FunctionalLocation::where('parent_id', $parent?->id)
            ->where('segment', $normalizedSegment)
            ->exists();

        if ($duplicateExists) {
            return back()
                ->withInput()
                ->withErrors(['segment' => 'Segment "' . $normalizedSegment . '" sudah dipakai oleh node lain di bawah parent yang sama.']);
        }

        $code = FunctionalLocation::buildCode($parent, $normalizedSegment);

        FunctionalLocation::create([
            'code'      => $code,
            'segment'   => $normalizedSegment,
            'name'      => $validated['name'],
            'level'     => $parent !== null ? $parent->level + 1 : FunctionalLocation::LEVEL_SITE,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ]);

        return redirect()
            ->route('func-locs.index')
            ->with('success', 'Functional Location "' . $code . '" berhasil dibuat.');
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
     * dapat diubah karena bersifat immutable setelah dibuat.
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
     * Label level dalam Bahasa Indonesia, dipakai untuk dropdown filter/form.
     *
     * @return array<int, string>
     */
    private function levelLabels(): array
    {
        return [
            FunctionalLocation::LEVEL_SITE       => 'Site',
            FunctionalLocation::LEVEL_DEPARTMENT => 'Departemen',
            FunctionalLocation::LEVEL_AREA       => 'Area',
            FunctionalLocation::LEVEL_SECTION    => 'Section',
        ];
    }
}
