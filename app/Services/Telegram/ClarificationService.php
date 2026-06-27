<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\SubArea;
use App\Services\FuncLocParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Menangani klarifikasi multi-level via Telegram inline keyboard.
 *
 * Flow:
 * 1. Jika user menyebut AREA → tampilkan equipment di area tersebut
 * 2. Jika TIDAK menyebut AREA, coba akselerasi FuncLoc (kode Dept/Area
 *    yang disebut di teks) → jika masih tidak ketemu, drill-down:
 *    Plant → Department → Area → SubArea (opsional) → Section → Tipe → Equipment
 * 3. State disimpan di Cache per chat_id
 *
 * F3: logika pencarian/matching TechIdentNo presisi ada di
 * TechIdentSearchService — service ini murni membangun keyboard hierarki
 * (termasuk pengelompokan Section/Tipe via FuncLocParser saat equipment
 * di satu area terlalu banyak untuk satu layar keyboard).
 */
class ClarificationService
{
    const CACHE_PREFIX = 'clarify_session:';
    const CACHE_TTL    = 1800; // 30 menit

    /** Jumlah maksimum opsi yang ditampilkan dalam satu layar keyboard (dokumen Bagian E). */
    protected const MAX_KEYBOARD_OPTIONS = 10;

    protected FuncLocParser $funcLocParser;

    public function __construct(FuncLocParser $funcLocParser)
    {
        $this->funcLocParser = $funcLocParser;
    }

    /**
     * Mulai atau lanjutkan sesi klarifikasi.
     *
     * Jika user mengirim teks BARU dan analysis menunjukkan confidence tinggi,
     * destroy sesi lama dan buat baru (jangan melanjutkan sesi dari pesan sebelumnya).
     */
    public function getOrCreateSession(string $chatId, string $text, array $analysis): array
    {
        $session = $this->getSession($chatId);

        // Jika sudah ada sesi aktif...
        if ($session && $session['status'] === 'waiting') {
            $confidence = $analysis['confidence'] ?? 0;

            // Jika analysis dari teks BARU menunjukkan confidence >= 60,
            // artinya user mengirim pesan yang jelas, destroy sesi klarifikasi lama
            if ($confidence >= 60) {
                $this->destroySession($chatId);
                $session = null;
                Log::info("Clarification: Session destroyed for chat {$chatId} because new message has confidence={$confidence}");
            } else {
                // Jika confidence rendah, berarti ini masih pesan tidak jelas,
                // lanjutkan sesi klarifikasi yang sudah ada
                return $session;
            }
        }

        // Buat sesi baru
        $session = [
            'chat_id'               => $chatId,
            'text'                  => $text,
            'analysis'              => $analysis,
            'level'                 => null, // akan ditentukan berdasarkan deteksi area
            'status'                => 'waiting',
            'selected_company_id'   => null,
            'selected_department_id'=> null,
            'selected_area_id'      => null,
            'selected_area_code'    => null,
            'selected_sub_area_id'  => null,
            'selected_section_code' => null,
            'selected_type_code'    => null,
            'selected_asset_id'     => null,
            'created_at'            => now()->toIso8601String(),
        ];

        // Cek apakah AI mendeteksi area
        $detectedAreaCode = $analysis['detected_area'] ?? null;

        // Jika AI mendeteksi equipment secara langsung, simpan sebagai saran saja —
// konfirmasi ke teknisi dilakukan oleh ReportWizardService (keyboard ✅/✏️).
// Jangan set level=done di sini agar teknisi selalu bisa mengoreksi.
$detectedEquipmentId = $analysis['detected_equipment_id'] ?? null;
if ($detectedEquipmentId) {
    $asset = Asset::find($detectedEquipmentId);
    if ($asset) {
        $session['suggested_asset_id']    = (int)$detectedEquipmentId;
        $session['suggested_asset_ident'] = $asset->tech_ident_no;

        // Isi lokasi hierarki sebagai konteks navigasi (tetap berguna),
        // tapi JANGAN set level=done atau status=completed.
        if ($asset->area_id) {
            $session['selected_area_id']       = $asset->area_id;
            $session['selected_area_code']     = $asset->area?->code;
            $session['selected_department_id'] = $asset->area?->department_id;
            $session['selected_company_id']    = $asset->area?->department?->company_id;
        }
        if ($asset->sub_area_id) {
            $session['selected_sub_area_id'] = $asset->sub_area_id;
        }
        // Biarkan level dan status default (null / 'waiting')
        // sehingga alur hierarki normal tetap berjalan jika diperlukan.
    }
}

        if ($detectedAreaCode) {
            // Support area code tanpa leading zero (misal "BD1" untuk "BD01")
            $searchCode = strtoupper($detectedAreaCode);
            $area       = Area::where('code', $searchCode)->first();
            if (!$area) {
                // Coba cari dengan leading zero jika tidak ditemukan
                if (preg_match('/^([A-Z]+)(\d)$/', $searchCode, $m)) {
                    $area = Area::where('code', $m[1] . '0' . $m[2])->first();
                }
            }
            if ($area) {
                $session['selected_area_id']   = $area->id;
                $session['selected_area_code'] = $area->code;
                // Otomatisi isi company & department dari relasi area
                if ($area->department) {
                    $session['selected_department_id'] = $area->department->id;
                    if ($area->department->company) {
                        $session['selected_company_id'] = $area->department->company->id;
                    }
                }
                // Cek apakah ada sub area
                $subAreas = SubArea::where('area_id', $area->id)->count();
                if ($subAreas > 0) {
                    $session['level'] = 'subarea_selection';
                } else {
                    $session['level'] = $this->nextLevelAfterAreaOrSubArea($area->id, null);
                }
            }
        }

        // F3 — Akselerasi FuncLoc (Step 3 wizard): jika AI/keyword tidak menemukan
        // area, coba cari kode Department/Area yang disebut langsung di teks
        // (mis. "PROD", "BD01") supaya teknisi tidak perlu klik company -> department
        // satu per satu. Hanya dijalankan kalau level belum ditentukan sama sekali.
        if (!$session['level']) {
            $accel = $this->funcLocParser->detectAccelerationCodes(
                $text,
                Department::all(['id', 'code', 'company_id']),
                Area::all(['id', 'code', 'department_id'])
            );

            if ($accel['area_id']) {
                $session['selected_area_id']       = $accel['area_id'];
                $session['selected_area_code']     = $accel['area_code'];
                $session['selected_department_id'] = $accel['department_id'];
                $session['selected_company_id']    = $accel['company_id'];

                $subAreas = SubArea::where('area_id', $accel['area_id'])->count();
                $session['level'] = ($subAreas > 0)
                    ? 'subarea_selection'
                    : $this->nextLevelAfterAreaOrSubArea($accel['area_id'], null);
            } elseif ($accel['department_id']) {
                $session['selected_department_id'] = $accel['department_id'];
                $session['selected_company_id']    = $accel['company_id'];
                $session['level'] = 'area_selection';
            }
        }

        // Jika area tidak terdeteksi, mulai dari company
        if (!$session['level']) {
            $session['level'] = 'company_selection';
        }

        $this->saveSession($chatId, $session);
        return $session;
    }

    // =========================================================
    // BUG 3 FIX
    // processSelection:
    //   Sebelumnya menolak semua callback jika status !== 'waiting'.
    //   Masalah: ketika equipment dipilih, status di-set ke 'completed'
    //   dan session di-save, tapi PollTelegramUpdates masih memanggil
    //   processSelection sekali lagi untuk auto-select loop. Dengan
    //   status 'completed', request ditolak → session 'done' tidak
    //   pernah bisa di-read oleh buildCurrentMessage dengan benar.
    //
    //   FIX: Izinkan status 'completed' jika level sudah 'done'
    //   (artinya session baru saja selesai pada callback ini sendiri).
    //   Request dengan status 'completed' dan level bukan 'done'
    //   tetap ditolak untuk mencegah modifikasi sesi yang sudah tutup.
    // =========================================================
    /**
     * Proses pilihan user dari inline keyboard
     */
    public function processSelection(string $chatId, string $callbackData): array
    {
        $session = $this->getSession($chatId);

        // BUG 3 FIX — Cek kondisi session yang valid:
        //   - Session tidak ada sama sekali → tolak
        //   - Status 'waiting' → proses normal
        //   - Status 'completed' dan level 'done' → izinkan (baru saja selesai)
        //   - Status 'completed' dan level bukan 'done' → tolak (sesi lama yang sudah tutup)
        if (!$session) {
            return ['success' => false, 'error' => 'Tidak ada sesi aktif'];
        }

        $isJustCompleted = ($session['status'] === 'completed' && $session['level'] === 'done');

        if ($session['status'] !== 'waiting' && !$isJustCompleted) {
            return ['success' => false, 'error' => 'Sesi sudah selesai atau tidak aktif'];
        }

        // Jika session sudah 'completed' (baru saja done), kembalikan langsung
        // tanpa memproses aksi apapun — PollTelegramUpdates akan baca msgData['done']
        if ($isJustCompleted) {
            return ['success' => true, 'session' => $session];
        }

        // Parse callback: level:action:id
        $parts  = explode(':', $callbackData);
        $level  = $parts[0] ?? '';
        $action = $parts[1] ?? '';
        $id     = $parts[2] ?? null;

        switch ($level) {
            case 'company':
                if ($action === 'select' && $id) {
                    $session['selected_company_id'] = (int)$id;
                    $session['level']               = 'department_selection';
                } elseif ($action === 'back') {
                    $session['level'] = 'company_selection';
                }
                break;

            case 'department':
                if ($action === 'select' && $id) {
                    $session['selected_department_id'] = (int)$id;
                    $session['level']                  = 'area_selection';
                } elseif ($action === 'back') {
                    $session['level']                 = 'company_selection';
                    $session['selected_company_id']   = null;
                }
                break;

            case 'area':
                if ($action === 'select' && $id) {
                    $session['selected_area_id'] = (int)$id;
                    $area                        = Area::find((int)$id);
                    $session['selected_area_code'] = $area ? $area->code : null;

                    // Cek sub area
                    $subAreas        = SubArea::where('area_id', (int)$id)->count();
                    $session['level'] = ($subAreas > 0)
                        ? 'subarea_selection'
                        : $this->nextLevelAfterAreaOrSubArea((int)$id, null);
                } elseif ($action === 'back') {
                    $session['level']                    = 'department_selection';
                    $session['selected_department_id']   = null;
                    $session['selected_area_id']         = null;
                    $session['selected_area_code']       = null;
                }
                break;

            case 'subarea':
                if ($action === 'select' && $id) {
                    $session['selected_sub_area_id'] = (int)$id;
                    $session['level'] = $this->nextLevelAfterAreaOrSubArea($session['selected_area_id'], (int)$id);
                } elseif ($action === 'skip') {
                    // Lewati sub area → langsung equipment (atau section dulu jika equipment banyak)
                    $session['level'] = $this->nextLevelAfterAreaOrSubArea($session['selected_area_id'], null);
                } elseif ($action === 'back') {
                    $session['level']                 = 'area_selection';
                    $session['selected_area_id']      = null;
                    $session['selected_area_code']    = null;
                    $session['selected_sub_area_id']  = null;
                }
                break;

            case 'section':
                if ($action === 'select' && $id) {
                    $session['selected_section_code'] = $id;
                    $session['level']                 = 'type_selection';
                } elseif ($action === 'back') {
                    $session['level'] = $session['selected_sub_area_id'] ? 'subarea_selection' : 'area_selection';
                    $session['selected_section_code'] = null;
                }
                break;

            case 'type':
                if ($action === 'select' && $id) {
                    $session['selected_type_code'] = $id;
                    $session['level']              = 'equipment_selection';
                } elseif ($action === 'back') {
                    $session['level']              = 'section_selection';
                    $session['selected_type_code'] = null;
                }
                break;

            case 'equipment':
                if ($action === 'select' && $id) {
                    $session['selected_asset_id'] = (int)$id;
                    $session['level']             = 'done';
                    $session['status']            = 'completed';
                } elseif ($action === 'skip') {
                    // Skip equipment → anggap pekerjaan area
                    $session['level']        = 'done';
                    $session['status']       = 'completed';
                    $session['is_area_work'] = true;
                } elseif ($action === 'back') {
                    if (!empty($session['selected_type_code'])) {
                        $session['level'] = 'type_selection';
                    } elseif (!empty($session['selected_section_code'])) {
                        $session['level'] = 'section_selection';
                    } elseif ($session['selected_sub_area_id']) {
                        $session['level']                 = 'subarea_selection';
                        $session['selected_asset_id']     = null;
                    } else {
                        $session['level']             = 'area_selection';
                        $session['selected_asset_id'] = null;
                    }
                }
                break;

            default:
                return ['success' => false, 'error' => 'Aksi tidak dikenal'];
        }

        $this->saveSession($chatId, $session);
        return ['success' => true, 'session' => $session];
    }

    /**
     * Bangun pesan dan keyboard untuk level saat ini
     */
    public function buildCurrentMessage(array $session): array
    {
        switch ($session['level']) {
            case 'company_selection':
                return $this->buildCompanySelection();

            case 'department_selection':
                return $this->buildDepartmentSelection($session['selected_company_id']);

            case 'area_selection':
                return $this->buildAreaSelection(
                    $session['selected_department_id'],
                    $session['selected_company_id']
                );

            case 'subarea_selection':
                return $this->buildSubAreaSelection($session['selected_area_id']);

            case 'section_selection':
                return $this->buildSectionSelection($session['selected_area_id'], $session['selected_sub_area_id']);

            case 'type_selection':
                return $this->buildTypeSelection(
                    $session['selected_area_id'],
                    $session['selected_sub_area_id'],
                    $session['selected_section_code']
                );

            case 'equipment_selection':
                return $this->buildEquipmentSelection(
                    $session['selected_area_id'],
                    $session['selected_sub_area_id'],
                    $session['selected_section_code'],
                    $session['selected_type_code']
                );

            case 'done':
                return $this->buildDoneMessage($session);

            default:
                return [
                    'message'  => 'Pilih area kerja:',
                    'keyboard' => [],
                ];
        }
    }

    /**
     * Pilih Perusahaan/Plant
     */
    private function buildCompanySelection(): array
    {
        $companies = Company::orderBy('name')->get();

        if ($companies->isEmpty()) {
            return [
                'message'  => 'Tidak ada data perusahaan. Hubungi admin.',
                'keyboard' => [],
            ];
        }

        // Jika hanya 1, langsung pilih otomatis
        if ($companies->count() === 1) {
            $company = $companies->first();
            return [
                'message'     => null,
                'keyboard'    => [],
                'auto_select' => true,
                'auto_level'  => 'company',
                'auto_id'     => $company->id,
            ];
        }

        $keyboard = [];
        foreach ($companies as $c) {
            $keyboard[] = [
                'text'          => $c->name,
                'callback_data' => "company:select:{$c->id}",
            ];
        }

        return [
            'message'  => '📋 Pilih Plant/Perusahaan:',
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Pilih Department (berdasarkan company)
     */
    private function buildDepartmentSelection(?int $companyId): array
    {
        $query = Department::orderBy('name');
        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        $departments = $query->get();

        if ($departments->isEmpty()) {
            return [
                'message'  => 'Tidak ada departemen untuk perusahaan ini.',
                'keyboard' => [],
            ];
        }

        // Auto-select jika hanya 1
        if ($departments->count() === 1) {
            $dept = $departments->first();
            return [
                'message'     => null,
                'keyboard'    => [],
                'auto_select' => true,
                'auto_level'  => 'department',
                'auto_id'     => $dept->id,
            ];
        }

        $keyboard = [];
        foreach ($departments as $d) {
            $keyboard[] = [
                'text'          => $d->name,
                'callback_data' => "department:select:{$d->id}",
            ];
        }
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'company:back'];

        return [
            'message'  => '📁 Pilih Departemen:',
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Pilih Area (berdasarkan department dan/atau company)
     */
    private function buildAreaSelection(?int $departmentId, ?int $companyId): array
    {
        $query = Area::with('department')->orderBy('code');

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        } elseif ($companyId) {
            $query->whereHas('department', fn($q) => $q->where('company_id', $companyId));
        }

        $areas = $query->get();

        if ($areas->isEmpty()) {
            // Tidak ada area, lewati ke equipment
            return [
                'message'  => null,
                'keyboard' => [],
                'skip'     => true,
            ];
        }

        $keyboard = [];
        foreach ($areas as $a) {
            $label    = $a->code . ' - ' . $a->name;
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "area:select:{$a->id}",
            ];
        }
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'department:back'];

        return [
            'message'  => '📍 Pilih Area Kerja:',
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Pilih Sub Area (opsional) — bisa dilewati
     */
    private function buildSubAreaSelection(int $areaId): array
    {
        $subAreas = SubArea::where('area_id', $areaId)->orderBy('name')->get();

        if ($subAreas->isEmpty()) {
            // Tidak ada sub area, skip otomatis
            return [
                'message'  => null,
                'keyboard' => [],
                'skip'     => true,
            ];
        }

        $area     = Area::find($areaId);
        $areaCode = $area ? $area->code : '';

        $keyboard = [];
        foreach ($subAreas as $sa) {
            $label = $sa->code ? "[{$sa->code}] {$sa->name}" : $sa->name;
            if (strlen($label) > 40) {
                $label = substr($label, 0, 37) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "subarea:select:{$sa->id}",
            ];
        }
        $keyboard[] = ['text' => '⏭ Lewati', 'callback_data' => 'subarea:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'area:back'];

        return [
            'message'  => "📍 Sub Area di {$areaCode}:\nPilih sub area (atau lewati):",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Ambil kandidat asset untuk area/sub area tertentu. Dipisah dari
     * buildEquipmentSelection supaya bisa dipakai ulang oleh
     * nextLevelAfterAreaOrSubArea() (hitung jumlah) dan buildSectionSelection()/
     * buildTypeSelection() (pengelompokan via FuncLocParser) tanpa duplikasi query.
     */
    private function fetchEquipmentCandidates(?int $areaId, ?int $subAreaId): Collection
    {
        $query = Asset::whereNotNull('tech_ident_no')->orderBy('tech_ident_no');

        if ($subAreaId) {
            // Jika sub area dipilih, cari equipment di sub area tersebut
            $query->where('sub_area_id', $subAreaId);
        } elseif ($areaId) {
            // Jika hanya area dipilih (tanpa sub area), cari equipment di area tsb
            // TAPI jangan include equipment yang sudah terikat ke sub area tertentu
            $query->where(function ($q) use ($areaId) {
                $q->where('area_id', $areaId)
                  ->whereNull('sub_area_id');
            });
        }

        // Limit pengaman query, BUKAN limit tampilan keyboard (itu diatur
        // MAX_KEYBOARD_OPTIONS di buildEquipmentSelection).
        return $query->limit(500)->get();
    }

    /**
     * Tentukan level berikutnya setelah Area/SubArea dipilih: langsung ke
     * equipment_selection jika jumlah equipment masih muat satu layar
     * keyboard, atau ke section_selection dulu (Sub-alur C) jika terlalu
     * banyak — sesuai aturan "tidak lebih dari 10 pilihan di satu layar"
     * di dokumen Bagian E.
     */
    private function nextLevelAfterAreaOrSubArea(?int $areaId, ?int $subAreaId): string
    {
        $count = $this->fetchEquipmentCandidates($areaId, $subAreaId)->count();

        return $count > self::MAX_KEYBOARD_OPTIONS ? 'section_selection' : 'equipment_selection';
    }

    /**
     * Sub-alur C — Level 1: pilih Section (kode 4-digit, mis. 6153/6160/6163).
     */
    private function buildSectionSelection(?int $areaId, ?int $subAreaId): array
    {
        $grouped = $this->funcLocParser->groupBySectionAndType(
            $this->fetchEquipmentCandidates($areaId, $subAreaId)
        );

        if (empty($grouped)) {
            // Tidak ada yang bisa dikelompokkan, lewati ke equipment apa adanya
            return ['message' => null, 'keyboard' => [], 'skip' => true];
        }

        $keyboard = [];
        foreach (array_keys($grouped) as $sectionCode) {
            $keyboard[] = [
                'text'          => "Section {$sectionCode}",
                'callback_data' => "section:select:{$sectionCode}",
            ];
        }
        $keyboard[] = ['text' => '⏭ Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'section:back'];

        return [
            'message'  => "🗂 Equipment di area ini cukup banyak. Pilih Section dulu:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Sub-alur C — Level 2: pilih Tipe alat (Pump/Vessel/Reactor/dst) di
     * dalam Section yang sudah dipilih.
     */
    private function buildTypeSelection(?int $areaId, ?int $subAreaId, ?string $sectionCode): array
    {
        $grouped = $this->funcLocParser->groupBySectionAndType(
            $this->fetchEquipmentCandidates($areaId, $subAreaId)
        );
        $types = $grouped[$sectionCode] ?? [];

        if (empty($types)) {
            // Section tidak ditemukan lagi (mis. data berubah), lewati ke equipment apa adanya
            return ['message' => null, 'keyboard' => [], 'skip' => true];
        }

        $keyboard = [];
        foreach (array_keys($types) as $typeCode) {
            $keyboard[] = [
                'text'          => $this->funcLocParser->typeLabel($typeCode) . " ({$typeCode})",
                'callback_data' => "type:select:{$typeCode}",
            ];
        }
        $keyboard[] = ['text' => '⏭ Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'type:back'];

        return [
            'message'  => "🗂 Section {$sectionCode} — Pilih Tipe Alat:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Pilih Equipment di area/sub area yang dipilih, opsional difilter
     * lebih lanjut oleh Section/Tipe hasil Sub-alur C.
     * TechIdentNo dan FunctionalLoc adalah kunci utama.
     */
    private function buildEquipmentSelection(
        ?int $areaId,
        ?int $subAreaId,
        ?string $sectionCode = null,
        ?string $typeCode = null
    ): array {
        $assets = $this->fetchEquipmentCandidates($areaId, $subAreaId);

        if ($sectionCode) {
            $assets = $assets->filter(
                fn ($a) => $this->funcLocParser->extractSectionCode($a->tech_ident_no ?? '') === $sectionCode
            )->values();
        }
        if ($typeCode) {
            $assets = $assets->filter(
                fn ($a) => $this->funcLocParser->extractEquipmentType($a->tech_ident_no ?? '') === $typeCode
            )->values();
        }

        $area      = $areaId ? Area::find($areaId) : null;
        $areaLabel = $area ? $area->code : 'Area ini';

        $keyboard = [];
        foreach ($assets->take(self::MAX_KEYBOARD_OPTIONS) as $a) {
            $ti   = $a->tech_ident_no ?? '';
            $fl   = $a->functional_loc ?? '';
            $desc = $a->description ?? '';

            $label = $ti;
            if ($fl) {
                $label .= " | {$fl}";
            }
            if ($desc) {
                $label .= " — {$desc}";
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "equipment:select:{$a->id}",
            ];
        }

        // Tombol skip — anggap pekerjaan area
        $keyboard[] = ['text' => '⏭ Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => $sectionCode ? 'type:back' : 'equipment:back'];

        if ($assets->isEmpty()) {
            return [
                'message'  => "🔧 Tidak ada equipment terdaftar di {$areaLabel}.\n\nKlik ⏭ Skip jika ini pekerjaan area (bukan perbaikan alat tertentu).",
                'keyboard' => $keyboard,
            ];
        }

        return [
            'message'  => "🔧 Pilih Equipment yang diperbaiki (atau ⏭ Skip jika pekerjaan area):",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Pesan setelah equipment dipilih atau skip (pekerjaan area)
     */

    private function buildDoneMessage(array $session): array
    {
        $isAreaWork = !empty($session['is_area_work']);
        $area       = $session['selected_area_id'] ? Area::find($session['selected_area_id']) : null;
        $subArea    = $session['selected_sub_area_id'] ? SubArea::find($session['selected_sub_area_id']) : null;

        if ($isAreaWork || !$session['selected_asset_id']) {
            $areaCode = $session['selected_area_code'] ?? ($area ? $area->code : '');

            $msg  = "✅ Pekerjaan Area dipilih!\n\n";
            $msg .= "📍 {$areaCode}";
            if ($subArea) {
                $code = $subArea->code ? "[{$subArea->code}] " : '';
                $msg .= " - {$code}{$subArea->name}";
            }
            $msg .= "\nIni akan dicatat sebagai pekerjaan area.\n";
            $msg .= "Silakan kirim deskripsi detail pekerjaan.";

            return [
                'message'          => $msg,
                'keyboard'         => [],
                'done'             => true,
                'is_area_work'     => true,
                'selected_area_id' => $session['selected_area_id'],
            ];
        }

        $asset = Asset::with(['area', 'subArea'])->find($session['selected_asset_id']);

        $msg  = "✅ Equipment dipilih!\n\n";
        if ($asset) {
            $msg .= "🔧 {$asset->tech_ident_no}";
            if ($asset->functional_loc) $msg .= " ({$asset->functional_loc})";
            $msg .= "\n";
            if ($asset->area)    $msg .= "📍 Area: {$asset->area->code} - {$asset->area->name}\n";
            if ($asset->subArea) {
                $code = $asset->subArea->code ? "[{$asset->subArea->code}] " : '';
                $msg .= "📌 Sub Area: {$code}{$asset->subArea->name}\n";
            }
        }

        return [
            'message'            => $msg,
            'keyboard'           => [],
            'done'               => true,
            'selected_asset_id'  => $session['selected_asset_id'],
        ];
    }

    // =========================================================
    // SESSION MANAGEMENT
    // =========================================================

    public function getSession(string $chatId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $chatId);
    }

    public function saveSession(string $chatId, array $session): void
    {
        Cache::put(self::CACHE_PREFIX . $chatId, $session, self::CACHE_TTL);
    }

    public function destroySession(string $chatId): void
    {
        Cache::forget(self::CACHE_PREFIX . $chatId);
    }
}
