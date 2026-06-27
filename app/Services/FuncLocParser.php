<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * FuncLocParser
 *
 * Mem-parsing struktur Functional Location & TechIdentNo, dan menyediakan
 * helper untuk akselerasi hierarki klarifikasi sesuai dokumen:
 *   - "E. Logika Pencarian TechIdentNo" -> Struktur Functional Loc
 *   - "D. Desain Alur Baru" -> Step 3: Akselerasi FuncLoc
 *   - "E. ... Sub-alur C" -> Pemecahan Kode di Hierarki
 *
 * Tanggung jawab service ini murni PARSING & PENGELOMPOKAN.
 * Pencarian/matching TechIdentNo presisi tetap jadi tanggung jawab
 * TechIdentSearchService (F2) — service ini tidak menduplikasi logika itu.
 */
class FuncLocParser
{
    /** Section code = 4 digit angka, contoh: 6163, 6160, 6020 (lihat dokumen Bagian E). */
    protected const SECTION_PATTERN = '/(\d{4})/';

    /** Label tipe alat untuk ditampilkan di keyboard Level 2 (Sub-alur C). */
    protected const TYPE_LABELS = [
        'P' => 'Pump',
        'V' => 'Vessel',
        'R' => 'Reactor',
        'E' => 'Heat Exchanger',
        'M' => 'Motor',
        'C' => 'Compressor',
        'T' => 'Tank',
        'B' => 'Blower',
    ];

    /**
     * Pecah string FuncLoc menjadi komponen Plant/Dept/Area/Section.
     * Format: [Plant]-[Dept]-[Area]-[Section] — 3 atau 4 segmen, dipisah "-".
     *
     * Contoh:
     *   EPE-PROD-BD01-6163 -> plant=EPE, dept=PROD, area=BD01, section=6163
     *   EPE-LOGE-UL01       -> plant=EPE, dept=LOGE, area=UL01, section=null
     */
    public function parseFuncLoc(?string $funcLoc): array
    {
        $result = ['plant' => null, 'dept' => null, 'area' => null, 'section' => null];

        if (!$funcLoc) {
            return $result;
        }

        $segments = explode('-', strtoupper(trim($funcLoc)));

        $result['plant']   = $segments[0] ?? null;
        $result['dept']    = $segments[1] ?? null;
        $result['area']    = $segments[2] ?? null;
        $result['section'] = $segments[3] ?? null;

        return $result;
    }

    /**
     * Ekstrak section code 4-digit dari TechIdentNo atau FuncLoc.
     * Contoh: "2-6163P4" -> "6163", "TCV-2-6166E2B-1" -> "6166".
     */
    public function extractSectionCode(string $value): ?string
    {
        if (preg_match(self::SECTION_PATTERN, $value, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Ekstrak huruf tipe alat (prefix huruf tepat setelah section 4-digit).
     * Contoh: "6163P4" -> "P", "6163E2" -> "E", "6166E2B-1" -> "E".
     */
    public function extractEquipmentType(string $techIdentNo): ?string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $techIdentNo));

        if (preg_match('/\d{4}([A-Z]+)\d/', $clean, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Label ramah-pengguna untuk kode tipe (P -> "Pump"). Jika tidak dikenal,
     * kembalikan kode aslinya supaya tetap tampil di keyboard.
     */
    public function typeLabel(string $typeCode): string
    {
        return self::TYPE_LABELS[$typeCode] ?? $typeCode;
    }

    /**
     * Deteksi kode Department / Area yang disebut di teks bebas teknisi,
     * untuk akselerasi (Step 3 wizard "Akselerasi FuncLoc"): jika kodenya
     * sudah jelas disebut, ClarificationService bisa loncat level hierarki
     * langsung ke level berikutnya tanpa user klik company -> department
     * satu per satu.
     *
     * Dicek lebih dulu kode AREA (lebih spesifik) baru DEPARTMENT, supaya
     * loncatan se-dalam mungkin jika keduanya disebut.
     *
     * @param  string     $text         Teks laporan awal dari teknisi
     * @param  Collection $departments  id, code, company_id
     * @param  Collection $areas        id, code, department_id
     * @return array{department_id: ?int, company_id: ?int, area_id: ?int, area_code: ?string}
     */
    public function detectAccelerationCodes(string $text, Collection $departments, Collection $areas): array
    {
        $textUpper = strtoupper(trim($text));
        $result    = ['department_id' => null, 'company_id' => null, 'area_id' => null, 'area_code' => null];

        // 1. Cari kode AREA dulu (contoh: BD01, TF01, UL01) — dukung tanpa leading zero
        $sortedAreas = $areas->sortByDesc(fn ($a) => strlen($a->code ?? ''));
        foreach ($sortedAreas as $area) {
            if (!$area->code) {
                continue;
            }

            $code = strtoupper($area->code);

            if (preg_match('/\b' . preg_quote($code, '/') . '\b/', $textUpper)) {
                $result['area_id']       = $area->id;
                $result['area_code']     = $area->code;
                $result['department_id'] = $area->department_id;
                break;
            }

            // Dukung tanpa leading zero: "BD1" cocok dengan "BD01"
            if (preg_match('/^([A-Z]+)0(\d)$/', $code, $m)) {
                $shortCode = $m[1] . $m[2];
                if (preg_match('/\b' . preg_quote($shortCode, '/') . '\b/', $textUpper)) {
                    $result['area_id']       = $area->id;
                    $result['area_code']     = $area->code;
                    $result['department_id'] = $area->department_id;
                    break;
                }
            }
        }

        // 2. Jika area belum ketemu, coba cari kode DEPARTMENT (contoh: PROD, LOGE, MTCE, QCRD)
        if (!$result['area_id']) {
            $sortedDepts = $departments->sortByDesc(fn ($d) => strlen($d->code ?? ''));
            foreach ($sortedDepts as $dept) {
                if (!$dept->code) {
                    continue;
                }

                $code = strtoupper($dept->code);
                if (preg_match('/\b' . preg_quote($code, '/') . '\b/', $textUpper)) {
                    $result['department_id'] = $dept->id;
                    $result['company_id']    = $dept->company_id;
                    break;
                }
            }
        } else {
            // Area sudah membawa department_id — lengkapi company_id juga jika tersedia
            $dept = $departments->firstWhere('id', $result['department_id']);
            if ($dept) {
                $result['company_id'] = $dept->company_id;
            }
        }

        return $result;
    }

    /**
     * Kelompokkan koleksi asset menjadi struktur Section -> Tipe -> [asset, ...]
     * sesuai Sub-alur C, supaya navigasi keyboard tidak pernah menampilkan
     * lebih dari batas opsi per layar (lihat contoh navigasi 3 level di
     * dokumen Bagian E).
     *
     * @param  Collection $assets
     * @return array<string, array<string, array>>
     */
    public function groupBySectionAndType(Collection $assets): array
    {
        $grouped = [];

        foreach ($assets as $asset) {
            $techIdentNo = $asset->tech_ident_no ?? '';
            $section     = $this->extractSectionCode($techIdentNo) ?? '0000';
            $type        = $this->extractEquipmentType($techIdentNo) ?? '?';

            $grouped[$section][$type][] = $asset;
        }

        ksort($grouped);

        return $grouped;
    }
}
