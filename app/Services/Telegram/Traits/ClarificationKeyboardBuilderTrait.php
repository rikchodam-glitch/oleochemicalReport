<?php

namespace App\Services\Telegram\Traits;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\SubArea;
use Illuminate\Support\Collection;

/**
 * ClarificationKeyboardBuilderTrait
 *
 * Membangun keyboard inline Telegram untuk setiap level navigasi hierarki
 * Functional Location dalam proses klarifikasi equipment:
 *   - buildCompanySelection()          : Level 0 — pilih Plant/Perusahaan
 *   - buildDepartmentSelection()       : Level 1 — pilih Departemen
 *   - buildAreaSelection()             : Level 2 — pilih Area Kerja
 *   - buildSubAreaSelection()          : Level 3 — pilih Sub Area (opsional)
 *   - buildSectionSelection()          : Sub-alur C Level 1 — pilih Section 4-digit
 *   - buildTypeSelection()             : Sub-alur C Level 2 — pilih Tipe Alat
 *   - buildEquipmentSelection()        : Level akhir — pilih Equipment spesifik
 *   - fetchEquipmentCandidates()       : Query asset untuk area/sub area tertentu
 *   - nextLevelAfterAreaOrSubArea()    : Tentukan level berikutnya (section atau equipment)
 *
 * Semua method di trait ini bersifat private — hanya diakses dari dalam
 * ClarificationService melalui buildCurrentMessage().
 *
 * Trait ini bergantung pada properti berikut dari kelas pemakai:
 *   - $this->funcLocParser: FuncLocParser
 *   - const MAX_KEYBOARD_OPTIONS: int
 */
trait ClarificationKeyboardBuilderTrait
{
    // =========================================================
    // LEVEL 0 — PLANT / PERUSAHAAN
    // =========================================================

    /**
     * Bangun keyboard pilihan Plant/Perusahaan.
     * Jika hanya ada 1, kembalikan sinyal auto_select agar langsung dipilih.
     *
     * @return array Respons dengan message, keyboard, atau auto_select
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
            'message'  => 'Pilih Plant/Perusahaan:',
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // LEVEL 1 — DEPARTEMEN
    // =========================================================

    /**
     * Bangun keyboard pilihan Departemen berdasarkan company_id.
     * Jika hanya ada 1, kembalikan sinyal auto_select.
     *
     * @param  int|null $companyId ID perusahaan yang dipilih
     * @return array    Respons dengan message, keyboard, atau auto_select
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
            'message'  => 'Pilih Departemen:',
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // LEVEL 2 — AREA KERJA
    // =========================================================

    /**
     * Bangun keyboard pilihan Area berdasarkan department_id atau company_id.
     * Jika tidak ada area sama sekali, kembalikan sinyal skip.
     *
     * @param  int|null $departmentId ID departemen yang dipilih
     * @param  int|null $companyId    ID perusahaan (fallback jika departemen tidak diketahui)
     * @return array    Respons dengan message, keyboard, atau skip
     */
    private function buildAreaSelection(?int $departmentId, ?int $companyId): array
    {
        $query = Area::with('department')->orderBy('code');

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        } elseif ($companyId) {
            $query->whereHas('department', fn ($q) => $q->where('company_id', $companyId));
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
            $label      = $a->code . ' - ' . $a->name;
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "area:select:{$a->id}",
            ];
        }
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'department:back'];

        return [
            'message'  => 'Pilih Area Kerja:',
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // LEVEL 3 — SUB AREA (OPSIONAL)
    // =========================================================

    /**
     * Bangun keyboard pilihan Sub Area dalam satu Area.
     * Jika tidak ada sub area, kembalikan sinyal skip (dilanjutkan otomatis).
     *
     * @param  int   $areaId ID area yang dipilih
     * @return array Respons dengan message, keyboard, atau skip
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
        $keyboard[] = ['text' => 'Lewati', 'callback_data' => 'subarea:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'area:back'];

        return [
            'message'  => "Sub Area di {$areaCode}:\nPilih sub area (atau lewati):",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // SUB-ALUR C — LEVEL 1: SECTION
    // =========================================================

    /**
     * Bangun keyboard pilihan Section (kode 4-digit, mis. 6153/6160/6163).
     * Ditampilkan ketika jumlah equipment di area/sub area melebihi MAX_KEYBOARD_OPTIONS.
     * Jika tidak ada section yang bisa dikelompokkan, kembalikan sinyal skip.
     *
     * @param  int|null $areaId    ID area aktif
     * @param  int|null $subAreaId ID sub area aktif (null jika belum dipilih)
     * @return array    Respons dengan message, keyboard, atau skip
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
        $keyboard[] = ['text' => 'Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'section:back'];

        return [
            'message'  => "Equipment di area ini cukup banyak. Pilih Section dulu:",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // SUB-ALUR C — LEVEL 2: TIPE ALAT
    // =========================================================

    /**
     * Bangun keyboard pilihan Tipe Alat (Pump/Vessel/Reactor/dst) di dalam
     * Section yang sudah dipilih.
     * Jika section tidak ditemukan (data berubah), kembalikan sinyal skip.
     *
     * @param  int|null    $areaId      ID area aktif
     * @param  int|null    $subAreaId   ID sub area aktif
     * @param  string|null $sectionCode Kode section 4-digit yang dipilih
     * @return array       Respons dengan message, keyboard, atau skip
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
        $keyboard[] = ['text' => 'Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => 'type:back'];

        return [
            'message'  => "Section {$sectionCode} — Pilih Tipe Alat:",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // LEVEL AKHIR — PILIH EQUIPMENT
    // =========================================================

    /**
     * Bangun keyboard pilihan Equipment spesifik di area/sub area tertentu,
     * opsional difilter lebih lanjut oleh Section dan Tipe (Sub-alur C).
     * Selalu menyertakan tombol "Skip" untuk pekerjaan area.
     *
     * @param  int|null    $areaId      ID area aktif
     * @param  int|null    $subAreaId   ID sub area aktif
     * @param  string|null $sectionCode Filter section (null = tidak difilter)
     * @param  string|null $typeCode    Filter tipe alat (null = tidak difilter)
     * @return array       Respons dengan message dan keyboard
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
        $keyboard[] = ['text' => 'Skip (Pekerjaan Area)', 'callback_data' => 'equipment:skip'];
        $keyboard[] = ['text' => '← Kembali', 'callback_data' => $sectionCode ? 'type:back' : 'equipment:back'];

        if ($assets->isEmpty()) {
            return [
                'message'  => "Tidak ada equipment terdaftar di {$areaLabel}.\n\nKlik Skip jika ini pekerjaan area (bukan perbaikan alat tertentu).",
                'keyboard' => $keyboard,
            ];
        }

        return [
            'message'  => "Pilih Equipment yang diperbaiki (atau Skip jika pekerjaan area):",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // HELPER QUERY & NAVIGASI
    // =========================================================

    /**
     * Ambil kandidat asset untuk area/sub area tertentu.
     * Dipisah dari buildEquipmentSelection agar bisa dipakai ulang oleh
     * nextLevelAfterAreaOrSubArea() (hitung jumlah) dan buildSectionSelection()
     * / buildTypeSelection() (pengelompokan via FuncLocParser) tanpa duplikasi query.
     *
     * @param  int|null    $areaId    ID area target
     * @param  int|null    $subAreaId ID sub area target (lebih spesifik dari area)
     * @return Collection            Koleksi asset yang memenuhi filter
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

        // Limit pengaman query — bukan limit tampilan keyboard
        // (tampilan diatur MAX_KEYBOARD_OPTIONS di buildEquipmentSelection)
        return $query->limit(500)->get();
    }

    /**
     * Tentukan level berikutnya setelah Area atau SubArea dipilih:
     * langsung ke equipment_selection jika masih muat satu layar keyboard,
     * atau ke section_selection dulu (Sub-alur C) jika terlalu banyak.
     * Batas ditentukan oleh konstanta MAX_KEYBOARD_OPTIONS.
     *
     * @param  int|null $areaId    ID area yang baru dipilih
     * @param  int|null $subAreaId ID sub area yang baru dipilih (null jika dilewati)
     * @return string              Nama level berikutnya
     */
    private function nextLevelAfterAreaOrSubArea(?int $areaId, ?int $subAreaId): string
    {
        $count = $this->fetchEquipmentCandidates($areaId, $subAreaId)->count();

        return $count > self::MAX_KEYBOARD_OPTIONS ? 'section_selection' : 'equipment_selection';
    }
}
