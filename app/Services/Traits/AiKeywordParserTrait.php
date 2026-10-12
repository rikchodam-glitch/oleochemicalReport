<?php

namespace App\Services\Traits;

use App\Models\AiAlias;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Report;

/**
 * Trait AiKeywordParserTrait
 *
 * Digunakan oleh AiService sebagai fallback ketika provider AI tidak tersedia.
 * Mengelompokkan semua logika yang berjalan murni berdasarkan kata kunci dan pola regex,
 * tanpa memanggil API eksternal.
 *
 * Method yang tersedia:
 *   - analyzeWithKeywords()         : Analisis teks laporan dengan keyword matching
 *   - detectArea()                  : Deteksi kode/nama area dari teks
 *   - detectAssetByTechIdent()      : Cari asset berdasarkan TechIdentNo (multi-pass)
 *   - firstMatch()                  : Helper query first-match dengan kondisi kolom
 *   - detectReportType()            : Tentukan tipe laporan dari kata kunci
 *   - checkAliases()                : Cek alias yang sudah dipelajari dari teks
 *   - parseWorkDurationMinutes()    : Ekstrak durasi pekerjaan dari teks (dalam menit)
 *   - parseRootCauseHint()          : Ekstrak potongan kalimat penyebab dari teks
 */
trait AiKeywordParserTrait
{
    /**
     * Fallback: analisis teks laporan menggunakan keyword matching.
     *
     * Prioritas: kode area → TechIdentNo (partial) → alias.
     * Area dideteksi terlebih dahulu untuk mempersempit pencarian equipment.
     *
     * @param string $text Teks laporan yang dianalisis
     * @return array Hasil analisis dengan kunci detected_area, detected_equipment, confidence, dll.
     */
    protected function analyzeWithKeywords(string $text): array
    {
        $result = [
            'report_type'             => 'general',
            'detected_area'           => null,
            'detected_equipment'      => null,
            'suggested_area'          => null,
            'suggested_equipment'     => null,
            'detected_equipment_id'   => null,
            'confidence'              => 0,
            'needs_clarification'     => false,
            'clarification_questions' => [],
            'message'                 => '',
        ];

        $result['report_type'] = $this->detectReportType($text);

        // 1. Cari area dari teks terlebih dahulu (untuk mempersempit pencarian equipment)
        $detectedArea = $this->detectArea($text);
        if ($detectedArea) {
            $result['detected_area']  = $detectedArea['code'];
            $result['suggested_area'] = $detectedArea['code'];
            $result['confidence']    += 40;
        }

        // 2. Cari equipment berdasarkan TechIdentNo — prioritaskan di area yang terdeteksi
        $detectedAsset = $this->detectAssetByTechIdent($text, $detectedArea['id'] ?? null);
        if ($detectedAsset) {
            $result['detected_equipment']    = $detectedAsset['tech_ident_no'];
            $result['suggested_equipment']   = $detectedAsset['tech_ident_no'];
            $result['detected_equipment_id'] = $detectedAsset['id'];
            $result['confidence']           += 60;

            // Equipment ketemu — override area dengan area dari equipment (lebih akurat)
            $asset = Asset::with('area')->find($detectedAsset['id']);
            if ($asset && $asset->area) {
                $result['detected_area']  = $asset->area->code;
                $result['suggested_area'] = $asset->area->code;
                $result['confidence']     = 90; // Yakin tinggi karena equipment terikat area
            }
        }

        // 3. Cek alias (hanya jika confidence masih rendah)
        if ($result['confidence'] < 60) {
            $aliasMatches = $this->checkAliases($text);
            if (!empty($aliasMatches['asset'])) {
                $result['detected_equipment']  = $aliasMatches['asset']->asset?->tech_ident_no ?? $aliasMatches['asset']->alias_text;
                $result['suggested_equipment'] = $aliasMatches['asset']->asset?->tech_ident_no;
                $result['confidence']          = max($result['confidence'], (int) ($aliasMatches['asset']->confidence * 100));
            }
            if (!empty($aliasMatches['area'])) {
                $result['detected_area']  = $aliasMatches['area']->area?->code ?? $aliasMatches['area']->alias_text;
                $result['suggested_area'] = $aliasMatches['area']->area?->code;
                $result['confidence']     = max($result['confidence'], (int) ($aliasMatches['area']->confidence * 100));
            }
        }

        // 4. Tentukan apakah perlu klarifikasi berdasarkan confidence akhir
        if ($result['confidence'] >= 60) {
            $result['needs_clarification'] = false;
            $result['message']             = 'Laporan diterima. Area dan equipment terdeteksi.';
        } elseif ($result['confidence'] >= 20) {
            $result['needs_clarification'] = false;
            $result['message']             = 'Laporan diterima. Sebagian informasi terdeteksi.';
        } else {
            $result['needs_clarification'] = true;
            $result['message']             = 'Informasi kurang jelas. Silakan pilih area kerja.';
        }

        return $result;
    }

    /**
     * Deteksi area dari teks — cari kode ATAU nama area yang disebut.
     *
     * Mendukung format kode dengan/tanpa leading zero:
     *   "BD01", "BD1", "RG1", "RG01", "EPE", "TF1", dll.
     * Juga mendukung nama Area yang disebut eksplisit (mis. "Boiler Deaerator"),
     * untuk kasus teknisi menulis nama lokasi alih-alih kode singkatnya.
     *
     * Pencarian dilakukan dalam 4 pass dari paling spesifik ke paling longgar:
     * 3 pass pertama berbasis kode (perilaku lama, tidak berubah), pass ke-4
     * berbasis nama Area (word boundary, diurutkan dari nama terpanjang agar
     * nama yang lebih spesifik menang lebih dulu).
     *
     * Catatan desain: method ini sengaja TIDAK mencocokkan SubArea. Struktur
     * hasil ['id','code','name'] merepresentasikan Area, dan dipakai apa
     * adanya sebagai detected_area/suggested_area oleh analyzeWithKeywords().
     * Resolusi lokasi sampai level SubArea sudah tercakup lewat
     * detectAssetByTechIdent(), yang mencari asset spesifik dan otomatis
     * membawa sub_area_id-nya — menambah SubArea di sini hanya akan
     * mengaburkan kontrak data tanpa kebutuhan nyata dari laporan bug ini.
     *
     * @param string $text Teks laporan
     * @return array|null Array berisi ['id', 'code', 'name'] atau null jika tidak ditemukan
     */
    protected function detectArea(string $text): ?array
    {
        $textUpper = strtoupper(trim($text));

        // Ambil semua area dari database
        $areas = Area::all(['id', 'code', 'name']);

        // Urutkan berdasarkan panjang code (descending) — cari yang paling spesifik dulu
        $sortedAreas = $areas->sortByDesc(fn($a) => strlen($a->code));

        // PASS 1: Exact match kode area di teks
        foreach ($sortedAreas as $area) {
            $code = strtoupper($area->code);
            if (str_contains($textUpper, $code)) {
                return ['id' => $area->id, 'code' => $area->code, 'name' => $area->name];
            }
        }

        // PASS 2: Handle kode area yang ditulis tanpa leading zero
        // Contoh: "BD1" harus cocok dengan kode "BD01"
        foreach ($sortedAreas as $area) {
            $code = strtoupper($area->code);

            if (preg_match('/^([A-Z]+)0(\d)$/', $code, $m)) {
                $shortCode = $m[1] . $m[2]; // "BD1"
                if (str_contains($textUpper, $shortCode)) {
                    // Pastikan bukan bagian dari kode yang lebih panjang
                    $pos      = strpos($textUpper, $shortCode);
                    $afterPos = substr($textUpper, $pos + strlen($shortCode), 1);
                    if (!empty($afterPos) && is_numeric($afterPos)) {
                        continue; // Ini bagian dari kode yang lebih panjang, skip
                    }
                    return ['id' => $area->id, 'code' => $area->code, 'name' => $area->name];
                }
            }
        }

        // PASS 3: Handle kode area 3-karakter tanpa leading zero seperti "TF1"
        foreach ($sortedAreas as $area) {
            $code = strtoupper($area->code);
            if (preg_match('/^([A-Z]+)0(\d)$/', $code, $m)) {
                $prefixOnly = $m[1]; // "BD" dari "BD01"
                if (preg_match('/\b' . preg_quote($prefixOnly, '/') . '(\d)\b/', $textUpper, $dm)) {
                    $shortCode = $prefixOnly . $dm[1];
                    if ($shortCode === $m[1] . $m[2]) {
                        return ['id' => $area->id, 'code' => $area->code, 'name' => $area->name];
                    }
                }
            }
        }

        // PASS 4: Cocokkan nama Area yang disebut eksplisit di teks (mis. "Boiler
        // Deaerator"), untuk kasus teknisi menulis nama lokasi, bukan kode singkatnya.
        // Diurutkan dari nama terpanjang agar nama yang lebih spesifik menang duluan
        // (mis. "Boiler Deaerator 2" tidak keburu cocok ke nama "Boiler" yang lebih umum).
        $sortedByName = $areas->filter(fn($a) => !empty(trim((string) $a->name)))
            ->sortByDesc(fn($a) => strlen($a->name));

        foreach ($sortedByName as $area) {
            $name = trim(strtoupper($area->name));

            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $textUpper)) {
                return ['id' => $area->id, 'code' => $area->code, 'name' => $area->name];
            }
        }

        return null;
    }

    /**
     * Cari asset berdasarkan TechIdentNo — mendukung partial match dengan prioritas bertingkat.
     *
     * Prioritas pencarian:
     *   1. Exact match
     *   2. TechIdentNo STARTS WITH candidate (prefix)
     *   3. Candidate STARTS WITH TechIdentNo (suffix)
     *   4. Partial match (contains) — hanya untuk kode yang cukup unik (>2 karakter atau campuran huruf+angka)
     *   5. Partial match pada functional_loc (jika area diketahui)
     *
     * @param string   $text   Teks laporan
     * @param int|null $areaId Batasi pencarian ke area tertentu jika diketahui
     * @return array|null Array berisi ['tech_ident_no', 'id'] atau null jika tidak ditemukan
     */
    protected function detectAssetByTechIdent(string $text, ?int $areaId = null): ?array
    {
        $text = strtoupper(trim($text));

        // Ekstrak semua kata/kode potensial dari teks
        // Mendukung format: "6163V7", "2-6153P1", "AC-TF-1-1", "V7"
        preg_match_all('/[A-Z0-9][A-Z0-9\.\-\/]+[A-Z0-9]|[A-Z0-9]{2,}/i', $text, $matches);
        $words = array_unique($matches[0]);

        // Filter kata yang relevan (min 2 karakter, max 30)
        $candidates = array_filter($words, fn($w) => strlen($w) >= 2 && strlen($w) <= 30);

        // Urutkan: yang lebih panjang/kompleks lebih diprioritaskan
        usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));

        // Singkirkan kode DAN nama area dari kandidat — jangan jadikan area
        // code/name sebagai equipment. Nama area disertakan di sini agar
        // konsisten dengan perluasan detectArea() (PASS 4) di atas: nama
        // lokasi yang kini ikut dikenali tidak boleh malah lolos jadi
        // token pencarian equipment (mis. jika nama Area kebetulan berupa
        // satu kata tanpa spasi yang mirip kode). Nama beberapa kata tidak
        // akan pernah cocok di sini karena regex ekstraksi kandidat di atas
        // tidak menangkap spasi — perbandingan tetap aman dilakukan.
        $areaRows       = Area::get(['code', 'name']);
        $areaCodes      = $areaRows->map(fn($a) => strtoupper($a->code))->toArray();
        $areaNames      = $areaRows->pluck('name')
            ->filter(fn($n) => trim((string) $n) !== '')
            ->map(fn($n) => strtoupper(trim($n)))
            ->values()
            ->toArray();
        $shortAreaCodes = [];
        foreach ($areaCodes as $ac) {
            if (preg_match('/^([A-Z]+)0(\d)$/', $ac, $m)) {
                $shortAreaCodes[] = $m[1] . $m[2]; // "BD1" dari "BD01"
            }
        }
        $allAreaVariants = array_merge($areaCodes, $shortAreaCodes, $areaNames);

        $candidates = array_values(array_filter($candidates, function ($w) use ($allAreaVariants) {
            return !in_array($w, $allAreaVariants);
        }));

        // Jika areaId diketahui, batasi pencarian ke area itu dulu
        $baseQuery   = Asset::whereNotNull('tech_ident_no');
        $scopedQuery = null;
        if ($areaId) {
            $scopedQuery = (clone $baseQuery)->where('area_id', $areaId);
        }

        foreach ($candidates as $candidate) {
            $cleanCandidate = strtoupper(preg_replace('/[^A-Z0-9]/', '', $candidate));

            // PRIORITAS 1: Exact match
            $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', $candidate);
            if ($asset) return $asset;
            if ($cleanCandidate !== $candidate) {
                $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', $cleanCandidate);
                if ($asset) return $asset;
            }

            // PRIORITAS 2: TechIdentNo STARTS WITH candidate (prefix)
            $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', "{$candidate}%", 'like');
            if ($asset) return $asset;
            if ($cleanCandidate !== $candidate) {
                $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', "{$cleanCandidate}%", 'like');
                if ($asset) return $asset;
            }

            // PRIORITAS 3: Candidate STARTS WITH TechIdentNo (suffix)
            $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', "%{$candidate}", 'like');
            if ($asset) return $asset;
            if ($cleanCandidate !== $candidate) {
                $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', "%{$cleanCandidate}", 'like');
                if ($asset) return $asset;
            }
        }

        // PRIORITAS 4: Partial match (contains) — hanya kode yang cukup unik
        foreach ($candidates as $candidate) {
            $cleanCandidate = strtoupper(preg_replace('/[^A-Z0-9]/', '', $candidate));
            $isShort        = strlen($cleanCandidate) <= 2;
            $hasMix         = preg_match('/[A-Z].*\d|\d.*[A-Z]/', $cleanCandidate);

            // Skip kode terlalu pendek (<=2) hanya jika tidak campuran huruf+angka
            if ($isShort && !$hasMix) {
                continue;
            }

            $asset = $this->firstMatch($scopedQuery ?? $baseQuery, 'tech_ident_no', "%{$cleanCandidate}%", 'like');
            if ($asset) return $asset;
        }

        // PRIORITAS 5: Cari berdasarkan functional_loc (jika area diketahui)
        if ($areaId && $scopedQuery) {
            foreach ($candidates as $candidate) {
                $cleanCandidate = strtoupper(preg_replace('/[^A-Z0-9]/', '', $candidate));
                $asset          = $this->firstMatch($scopedQuery, 'functional_loc', "%{$cleanCandidate}%", 'like');
                if ($asset) return $asset;
            }
        }

        return null;
    }

    /**
     * Helper: ambil record pertama dari query dengan kondisi kolom tertentu.
     *
     * @param mixed  $query    Query builder (bisa null)
     * @param string $column   Nama kolom yang dicek
     * @param string $value    Nilai yang dicari
     * @param string $operator Operator perbandingan ('=', 'like', dll.)
     * @return array|null Array berisi ['tech_ident_no', 'id'] atau null jika tidak ditemukan
     */
    private function firstMatch($query, string $column, string $value, string $operator = '='): ?array
    {
        if (!$query) return null;

        $asset = (clone $query)->where($column, $operator, $value)->first();
        if ($asset) {
            return ['tech_ident_no' => $asset->tech_ident_no, 'id' => $asset->id];
        }

        return null;
    }

    /**
     * Deteksi tipe laporan dari teks berdasarkan kata kunci.
     *
     * @param string $text Teks laporan
     * @return string 'equipment_repair' | 'area_work' | 'general'
     */
    protected function detectReportType(string $text): string
    {
        $textLower = strtolower(trim($text));

        $equipmentKeywords = [
            'pompa', 'motor', 'panel', 'valve', 'sensor', 'transmitter',
            'switch', 'lampu', 'ac', 'kompresor', 'bearing', 'pully',
            'belt', 'coupling', 'seal', 'gasket', 'pipa', 'tangki',
            'heater', 'cooler', 'exchanger', 'filter', 'hydrant',
            'apar', 'grounding', 'kabel', 'relay', 'kontaktor',
            'genset', 'blower', 'agitator', 'mixer', 'conveyor',
            'trafo', 'inverter', 'vfd', 'flowmeter', 'level switch',
            'temperature', 'pressure', 'gauge', 'thermocouple',
            'solenoid', 'cylinder', 'actuator', 'positioner',
        ];

        $areaKeywords = [
            'kebersihan', 'pengecatan', 'bangunan', 'atap', 'lantai',
            'dinding', 'pagar', 'jalan', 'selokan', 'drainase',
            'taman', 'rumput', 'penerangan area', 'perbaikan area',
            'pekerjaan area',
        ];

        foreach ($equipmentKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                return 'equipment_repair';
            }
        }

        foreach ($areaKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                return 'area_work';
            }
        }

        return 'general';
    }

    /**
     * Cek apakah teks mengandung alias yang sudah dipelajari.
     *
     * Hanya alias dengan status 'confirmed' atau 'pending' yang diikutsertakan.
     * Jika alias cocok, usage_count di-increment otomatis.
     *
     * @param string $text Teks laporan
     * @return array Array berisi kunci 'asset' dan 'area' (masing-masing bisa null atau objek AiAlias)
     */
    protected function checkAliases(string $text): array
    {
        $result    = ['asset' => null, 'area' => null];
        $textUpper = strtoupper(trim($text));

        $aliases = AiAlias::whereIn('status', ['confirmed', 'pending'])
            ->with(['asset', 'area'])
            ->get();

        foreach ($aliases as $alias) {
            $aliasText = strtoupper($alias->alias_text);

            if (str_contains($textUpper, $aliasText)) {
                if ($alias->asset_id && $alias->asset) {
                    $result['asset'] = $alias;
                    $alias->increment('usage_count');
                } elseif ($alias->area_id && $alias->area) {
                    $result['area'] = $alias;
                    $alias->increment('usage_count');
                }
            }
        }

        return $result;
    }

    /**
     * Coba ekstrak estimasi durasi pekerjaan (dalam menit) dari teks awal teknisi.
     *
     * Pola yang dikenali:
     *   - "2 jam", "1,5 jam", "90 menit", "2 jam 30 menit"
     *   - Rentang waktu: "08:00 sampai 09:00", "8:00-9:00", "08.00 s/d 09.30", "08:00 sd 10:00"
     *   - Format singkat: "1h30m", "1h 30m", "0h45m"
     *   - Jam:menit numerik: "1:30" (= 90 menit)
     * Hasil dipakai untuk pre-fill Step 4 (Waktu Pengerjaan) wizard agar teknisi
     * tidak perlu mengetik ulang jika durasi sudah disebut di pesan pertama.
     * Hasil divalidasi: tidak boleh 0/negatif dan tidak boleh lebih dari 24 jam (1440 menit).
     *
     * @param string $text Teks laporan
     * @return int|null Total menit yang diekstrak, atau null jika tidak ditemukan pola durasi
     */
    protected function parseWorkDurationMinutes(string $text): ?int
    {
        $textLower = strtolower($text);

        // Format rentang waktu — dicek lebih dulu karena mengandung pola jam:menit
        // yang bisa salah tertangkap oleh pola "jam:menit numerik" di bawah
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*(?:sampai|s\/d|sd|hingga|\-)\s*(\d{1,2})[:.](\d{2})/', $textLower, $m)) {
            // Satu sumber aturan: Report::durasiDariJam() (rentang melewati
            // tengah malam ditambah 1440 menit, jam selesai 24:00 = akhir hari,
            // jam mulai sama dengan jam selesai = tidak sah).
            $durasi = Report::durasiDariJam(
                sprintf('%02d:%02d', (int) $m[1], (int) $m[2]),
                sprintf('%02d:%02d', (int) $m[3], (int) $m[4])
            );

            return $this->validateWorkDurationMinutes($durasi);
        }

        $totalMinutes = 0;
        $found        = false;

        // Pola "X jam" (X boleh desimal pakai koma atau titik)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam/', $textLower, $m)) {
            $hours = (float) str_replace(',', '.', $m[1]);
            $totalMinutes += (int) round($hours * 60);
            $found = true;
        }

        // Pola "Y menit" — dijumlahkan dengan jam jika keduanya disebut (contoh: "2 jam 30 menit")
        if (preg_match('/(\d+)\s*menit/', $textLower, $m)) {
            $totalMinutes += (int) $m[1];
            $found = true;
        }

        if ($found) {
            return $this->validateWorkDurationMinutes($totalMinutes);
        }

        // Format singkat: "1h30m", "1h 30m", "0h45m"
        if (preg_match('/\b(\d+)\s*h\s*(?:(\d+)\s*m)?\b/', $textLower, $m)) {
            $hours   = (int) $m[1];
            $minutes = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return $this->validateWorkDurationMinutes($hours * 60 + $minutes);
        }

        // Format jam:menit numerik: "1:30" (= 90 menit)
        if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $textLower, $m)) {
            $hours   = (int) $m[1];
            $minutes = (int) $m[2];

            return $this->validateWorkDurationMinutes($hours * 60 + $minutes);
        }

        return null;
    }

    /**
     * Validasi hasil parsing durasi pekerjaan.
     * Durasi tidak boleh 0/negatif dan tidak boleh lebih dari 24 jam (1440 menit).
     *
     * @param  int|null    $minutes Durasi mentah hasil parsing
     * @return int|null             Durasi jika valid, null jika di luar rentang
     */
    private function validateWorkDurationMinutes(?int $minutes): ?int
    {
        if ($minutes === null || $minutes <= 0 || $minutes > 1440) {
            return null;
        }

        return $minutes;
    }

    /**
     * Coba ekstrak potongan kalimat yang mengindikasikan root cause dari teks awal.
     *
     * Mengenali pola umum: "karena ...", "akibat ...", "disebabkan ...", "penyebab ...".
     * Hasil ini hanya hint awal — teknisi tetap dikonfirmasi/diminta lengkapi di Step 5,
     * sesuai aturan root_cause wajib diisi minimal 3 karakter.
     *
     * @param string $text Teks laporan
     * @return string|null Potongan kalimat root cause, atau null jika tidak ditemukan
     */
    protected function parseRootCauseHint(string $text): ?string
    {
        $patterns = [
            '/\bkarena\s+(.+)/i',
            '/\bakibat\s+(.+)/i',
            '/\bdisebabkan\s+(?:oleh\s+)?(.+)/i',
            '/\bpenyebab(?:nya)?\s*:?\s+(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $hint = trim($m[1]);
                // Potong di akhir kalimat pertama agar tidak ikut kebawa kalimat berikutnya
                $hint = trim(preg_split('/[.!?\n]/', $hint)[0]);

                if (mb_strlen($hint) >= 3) {
                    return $hint;
                }
            }
        }

        return null;
    }
}
