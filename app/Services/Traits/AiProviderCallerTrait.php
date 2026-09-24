<?php

namespace App\Services\Traits;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trait AiProviderCallerTrait
 *
 * Digunakan oleh AiService untuk semua interaksi dengan provider AI eksternal (Groq/LLM).
 * Mengelompokkan logika pemilihan provider, pemanggilan API, logging penggunaan,
 * dan pembersihan respons dari format markdown.
 *
 * Method yang tersedia:
 *   - analyzeWithAi()              : Analisis teks laporan via AI provider yang dipilih
 *   - searchEquipmentWithAi()      : Pencarian equipment via AI (lapis kedua setelah regex no_match)
 *   - prefilterAssetCandidates()   : Pre-filter kandidat asset dari teks sebelum dikirim ke AI
 *   - searchLocationWithAi()       : Pencarian lokasi/area (functional_locations) via AI
 *                                    (lapis ketiga, setelah pencarian equipment AI juga gagal)
 *   - prefilterLocationCandidates(): Pre-filter kandidat functional_location dari teks
 *   - callGroq()                   : Kirim request ke Groq API dan catat penggunaan token
 *   - stripJsonFence()             : Hapus markdown code fence dari respons Groq
 *   - getBestProvider()            : Pilih provider AI terbaik yang tersedia (healthy + ada kuota)
 */
trait AiProviderCallerTrait
{
    /**
     * Model Groq bawaan bila kolom model pada provider kosong. Nama model
     * WAJIB salah satu yang tersedia di akun (lihat GET /openai/v1/models);
     * nilai lama "groq/compound" tidak ada di akun sehingga selalu HTTP 404
     * "model does not exist". Daftar model akun saat ini antara lain:
     * openai/gpt-oss-120b, openai/gpt-oss-20b, qwen/qwen3.8-27b.
     */
    const DEFAULT_GROQ_MODEL = 'openai/gpt-oss-120b';

    /**
     * Jumlah maksimum kandidat asset yang dikirim ke prompt AI.
     * Pre-filter keyword-based akan memangkas ribuan asset menjadi maksimum
     * sebanyak ini, sehingga token yang dikirim tetap hemat.
     */
    const MAX_ASSET_CANDIDATES_FOR_AI = 80;

    /**
     * Jumlah maksimum kandidat functional_location yang dikirim ke prompt AI,
     * dengan alasan yang sama seperti MAX_ASSET_CANDIDATES_FOR_AI.
     */
    const MAX_LOCATION_CANDIDATES_FOR_AI = 60;

    /**
     * Analisis teks laporan menggunakan provider AI (Groq/LLM).
     *
     * Membangun prompt dengan daftar area dan equipment dari database,
     * lalu meneruskannya ke Groq. Equipment dikirim dalam bentuk pre-filtered
     * (keyword-based), bukan dump seluruh tabel, untuk menghemat token.
     *
     * Catatan bug yang sudah diperbaiki:
     *   BUG 1 — relasi $a->area dan $a->subArea bisa null, menyebabkan
     *            "Trying to get property of non-object". Diperbaiki dengan null-safe operator.
     *   BUG 5 — Groq kadang mengembalikan ```json ... ```, json_decode gagal.
     *            Diperbaiki dengan stripJsonFence() sebelum decode.
     *
     * @param string $text Teks laporan yang akan dianalisis
     * @return array|null Hasil analisis atau null jika AI tidak tersedia/gagal
     */
    protected function analyzeWithAi(string $text): ?array
    {
        $provider = $this->getBestProvider();
        if (!$provider) {
            Log::info('AI Service: No provider available, using keyword fallback');
            return null;
        }

        try {
            // Ambil daftar area
            $areas = Area::all()
                ->map(fn($a) => "{$a->code} - {$a->name}")
                ->values()
                ->implode('; ');

            // Pre-filter kandidat equipment berdasarkan keyword dari teks,
            // bukan dump seluruh tabel (sebelumnya limit(300) tanpa filter)
            $candidates = $this->prefilterAssetCandidates($text);
            $equipments = collect($candidates)
                ->map(function ($a) {
                    $areaCode    = $a['area_code']    ?? '-';
                    $subAreaName = $a['sub_area_name'] ?? '-';
                    return "ID:{$a['id']} | TechIdentNo:{$a['tech_ident_no']} | FuncLoc:{$a['functional_loc']} | Desc:{$a['description']} | Area:{$areaCode} | SubArea:{$subAreaName}";
                })
                ->implode("\n");

            Log::info('AI Service: Pre-filtered candidates sent to AI', [
                'total_assets_in_db' => Asset::count(),
                'prefiltered_count'  => count($candidates),
            ]);

            $prompt = <<<PROMPT
Kamu adalah asisten analis laporan maintenance pabrik oleochemical.

Teks laporan: "{$text}"

DAFTAR AREA (kode dan nama):
{$areas}

DAFTAR EQUIPMENT (TechIdentNo dan Description adalah DUA SUMBER PENTING untuk identifikasi alat):
{$equipments}

ATURAN PENTING:
1. Teknisi bisa menyebut TechIdentNo alat ATAU mendeskripsikan alat dengan kata-kata bebas.
2. TechIdentNo bisa berupa: "6163V7", "2-6153P1", "AC-TF-1-1", "V7", "6153P1", dll.
3. Jika teks mengandung angka/huruf yang mirip bagian dari TechIdentNo, cari partial match di daftar equipment.
4. JIKA TIDAK ADA TechIdentNo yang cocok, coba cocokkan kata-kata di teks laporan dengan kolom Description.
   Contoh: teks "motor bleaching 1" harus cocok dengan Description yang mengandung kata "MOTOR" dan "Bleaching".
5. Deskripsi alat seringkali berisi nama umum komponen (Motor, Pompa, Valve, Sensor, dll.) yang bisa
   dicocokkan meskipun TechIdentNo tidak disebut sama sekali.

TUGAS:
1. Cari AREA kerja: cocokkan teks dengan kode area (BD1, BD01, RG1, RG01, EPE, TF1, MD1, EN1, UT1, dll).
   Jika ada yang cocok, isi detected_area dengan kode areanya. Jika tidak, null.
2. Cari EQUIPMENT: cocokkan teks dengan TechIdentNo (prioritas utama) ATAU dengan Description
   (jika TechIdentNo tidak disebut). Cari partial match jika user menyebut kode parsial atau nama komponen.
3. Jika equipment ditemukan, isi detected_equipment_id dengan ID numeric dari equipment tersebut.
4. Tentukan jenis laporan: equipment_repair (jika ada equipment disebut),
   area_work (jika hanya area/pekerjaan bangunan), atau general.
5. confidence: 0-100. Beri tinggi (80-100) jika equipment cocok exact/jelas.
   Rendah (<40) jika hanya partial match atau tidak yakin.
6. Jika informasi cukup jelas (confidence >= 60), set needs_clarification=false.
7. Jika sama sekali tidak jelas, set needs_clarification=true.

Balas HANYA JSON (tanpa markdown, tanpa tag):
{
  "report_type": "equipment_repair|area_work|general",
  "detected_area": "kode area atau null",
  "detected_equipment": "TechIdentNo atau null",
  "detected_equipment_id": "ID numeric dari equipment atau null",
  "confidence": 0-100,
  "needs_clarification": true/false,
  "clarification_questions": ["pertanyaan singkat"],
  "message": "pesan ramah untuk teknisi dalam Bahasa Indonesia"
}
PROMPT;

            $response = $this->callGroq($provider, $prompt);
            if ($response) {
                // BUG 5 FIX — strip markdown code fence jika Groq mengembalikan
                // ```json ... ``` atau ``` ... ``` sebelum di-decode.
                $cleaned = $this->stripJsonFence($response);

                $parsed = json_decode($cleaned, true);
                if ($parsed && isset($parsed['report_type'])) {
                    $parsed['suggested_area']      = $parsed['detected_area']      ?? null;
                    $parsed['suggested_equipment'] = $parsed['detected_equipment'] ?? null;
                    return $parsed;
                }

                Log::warning('AI Service: Failed to parse JSON response', [
                    'raw'     => substr($response, 0, 200),
                    'cleaned' => substr($cleaned, 0, 200),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("AI Service analyze error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Pencarian equipment via AI — lapis kedua setelah regex (TechIdentSearchService)
     * mengembalikan no_match. Method ini khusus mencari equipment berdasarkan
     * deskripsi alat yang disebut teknisi dalam bahasa alami.
     *
     * Berbeda dengan analyzeWithAi() yang mencari area+equipment+tipe laporan sekaligus,
     * method ini fokus hanya pada pencocokan equipment. Data yang dikirim ke AI
     * sudah di-pre-filter berdasarkan keyword dari teks.
     *
     * Mengembalikan hasil dengan format yang konsisten dengan TechIdentSearchService
     * agar bisa langsung digunakan oleh buildCandidateKeyboard() dan flow konfirmasi.
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu jika diketahui
     * @return array|null Hasil pencarian (format: status, candidates, confidence) atau null jika gagal
     */
    protected function searchEquipmentWithAi(string $text, ?int $areaId = null): ?array
    {
        $provider = $this->getBestProvider();
        if (!$provider) {
            Log::info('AI Service: No provider available for equipment search');
            return null;
        }

        try {
            // Pre-filter kandidat berdasarkan keyword dari teks
            $candidates = $this->prefilterAssetCandidates($text, $areaId);

            if (empty($candidates)) {
                Log::info('AI Service: Pre-filter returned zero candidates, skip AI call');
                return null;
            }

            $equipments = collect($candidates)
                ->map(function ($a) {
                    $areaCode    = $a['area_code']    ?? '-';
                    $subAreaName = $a['sub_area_name'] ?? '-';
                    return "ID:{$a['id']} | TechIdentNo:{$a['tech_ident_no']} | FuncLoc:{$a['functional_loc']} | Desc:{$a['description']} | Area:{$areaCode} | SubArea:{$subAreaName}";
                })
                ->implode("\n");

            Log::info('AI Service: Equipment search pre-filtered candidates', [
                'total_assets_in_db' => Asset::count(),
                'prefiltered_count'  => count($candidates),
                'area_id'            => $areaId,
            ]);

            $prompt = <<<PROMPT
Kamu adalah asisten pencarian equipment di pabrik oleochemical.

Teks laporan teknisi: "{$text}"

DAFTAR KANDIDAT EQUIPMENT:
{$equipments}

TUGAS KAMU:
1. Baca teks laporan baik-baik. Teknisi mungkin menyebut:
   - TechIdentNo (kode alat) seperti "6163V7", "V7", "2-6153P1"
   - Nama/deskripsi alat seperti "motor bleaching 1", "pompa transfer", "AC ruang panel"
   - Kombinasi keduanya
2. Cocokkan dengan daftar kandidat di atas. Prioritas pencocokan:
   a. TechIdentNo exact atau partial match (jika teknisi menyebut kode)
   b. Description mengandung kata-kata yang disebut teknisi (jika teknisi mendeskripsikan alat)
3. Jika TIDAK YAKIN (confidence < 60), lebih baik mengembalikan candidates kosong daripada menebak.
4. Jika YAKIN (confidence >= 60), isi candidates dengan daftar ID equipment yang paling cocok (maks 4 kandidat).

ATURAN OUTPUT:
- candidates: array of objects dengan field "id" (numeric), "tech_ident_no", "description",
  "functional_loc", "confidence" (0-100 individual)
- message: penjelasan singkat dalam Bahasa Indonesia kenapa kandidat tersebut dipilih

Balas HANYA JSON (tanpa markdown, tanpa tag):
{
  "status": "auto_accept|confirm|no_match",
  "confidence": 0-100,
  "candidates": [
    {"id": 123, "tech_ident_no": "xxx", "description": "xxx", "functional_loc": "xxx", "confidence": 90}
  ],
  "message": "penjelasan singkat dalam Bahasa Indonesia"
}
PROMPT;

            $response = $this->callGroq($provider, $prompt);
            if ($response) {
                $cleaned = $this->stripJsonFence($response);
                $parsed  = json_decode($cleaned, true);

                if ($parsed && isset($parsed['status'])) {
                    // Hanya kembalikan hasil jika AI cukup yakin (confidence >= 60)
                    if (($parsed['confidence'] ?? 0) >= 60 && !empty($parsed['candidates'])) {
                        Log::info('AI Service: Equipment search found candidates', [
                            'confidence'      => $parsed['confidence'],
                            'candidate_count' => count($parsed['candidates']),
                        ]);

                        // Format hasil agar konsisten dengan TechIdentSearchService
                        return [
                            'status'     => $parsed['status'] === 'auto_accept' ? 'auto_accept' : 'confirm',
                            'pass'       => 'ai_description',
                            'confidence' => $parsed['confidence'] ?? 70,
                            'candidates' => array_map(function ($c) {
                                return [
                                    'id'             => $c['id'] ?? null,
                                    'tech_ident_no'  => $c['tech_ident_no'] ?? '',
                                    'description'    => $c['description'] ?? '',
                                    'functional_loc' => $c['functional_loc'] ?? '',
                                ];
                            }, $parsed['candidates'] ?? []),
                            'ai_message' => $parsed['message'] ?? '',
                        ];
                    }

                    Log::info('AI Service: Equipment search low confidence or empty candidates', [
                        'confidence'      => $parsed['confidence'] ?? 0,
                        'candidate_count' => count($parsed['candidates'] ?? []),
                    ]);
                } else {
                    Log::warning('AI Service: Failed to parse equipment search JSON', [
                        'raw'     => substr($response, 0, 200),
                        'cleaned' => substr($cleaned, 0, 200),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("AI Service equipment search error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Ekstrak kata kunci pencarian dari teks laporan bebas.
     *
     * Dipakai bersama oleh prefilterAssetCandidates() (pencarian equipment)
     * dan prefilterLocationCandidates() (pencarian lokasi/area), agar logika
     * tokenisasi dan daftar stop word tidak terduplikasi.
     *
     * Langkah: ekstrak token alfanumerik minimal 2 karakter, buang stop word
     * umum Bahasa Indonesia/Inggris, buang kata di $extraExclusions (mis. kode
     * area saat dipakai untuk pencarian equipment), lalu urutkan dari yang
     * paling panjang (lebih spesifik) dan batasi maksimal 15 kata kunci.
     *
     * @param  string   $text            Teks laporan dari teknisi
     * @param  string[] $extraExclusions Kata tambahan yang harus dibuang dari hasil (opsional)
     * @return string[] Daftar kata kunci, terurut dari yang paling panjang
     */
    private function extractSearchKeywords(string $text, array $extraExclusions = []): array
    {
        $textUpper = strtoupper(trim($text));

        // Ekstrak semua kata dengan minimal 2 karakter (alfanumerik, minus, slash)
        preg_match_all('/[A-Z0-9][A-Z0-9\.\-\/]+[A-Z0-9]|[A-Z0-9]{2,}/iu', $textUpper, $matches);
        $words = array_unique($matches[0] ?? []);

        // Stop words Bahasa Indonesia/Inggris yang umum di teks laporan
        $stopWords = [
            'DI', 'KE', 'DARI', 'PADA', 'DENGAN', 'DAN', 'ATAU', 'INI', 'ITU',
            'YANG', 'ADA', 'SUDAH', 'AKAN', 'TIDAK', 'BUKAN', 'SAYA', 'KAMI',
            'THE', 'AND', 'FOR', 'HAS', 'WAS', 'NOT', 'BUT', 'ARE', 'ALL',
            'JAM', 'SAMPAI', 'PUKUL', 'SD', 'SELESAI', 'MULAI', 'LAPORAN',
            'MOHON', 'TOLONG', 'MENGALAMI', 'TERJADI', 'KARENA', 'AKIBAT',
            'PERBAIKAN', 'PENGGANTIAN', 'MAINTENANCE', 'PEKERJAAN',
            'DIPERBAIKI', 'DIGANTI', 'DICEK', 'DILAKUKAN', 'HARI', 'TANGGAL',
            'KEMARIN', 'HARIINI', 'SEKARANG', 'LAGI', 'SUPPLY', 'TEGANGAN',
            'SEHINGGA', 'DAPAT', 'DALAM', 'UNTUK', 'BARU', 'SETELAH',
        ];

        $extraExclusionsUpper = array_map(fn($w) => strtoupper($w), $extraExclusions);

        // Filter kata: buang stop word, pengecualian tambahan, dan kata terlalu pendek (1 karakter)
        $keywords = array_filter($words, function ($w) use ($stopWords, $extraExclusionsUpper) {
            $wUpper = strtoupper($w);
            if (strlen($wUpper) <= 1) {
                return false;
            }
            if (in_array($wUpper, $stopWords, true)) {
                return false;
            }
            if (in_array($wUpper, $extraExclusionsUpper, true)) {
                return false;
            }
            return true;
        });

        // Urutkan berdasarkan panjang (descending) — kata lebih panjang lebih spesifik
        usort($keywords, fn($a, $b) => strlen($b) <=> strlen($a));

        // Batasi jumlah keyword agar query LIKE tidak terlalu berat
        return array_slice(array_values($keywords), 0, 15);
    }

    /**
     * Pre-filter kandidat asset dari teks laporan sebelum dikirim ke prompt AI.
     *
     * Mengekstrak kata kunci dari teks (minimal 2 karakter, bukan stop word,
     * bukan kode area) via extractSearchKeywords(), lalu mencari asset yang
     * mengandung kata kunci tersebut di kolom tech_ident_no ATAU description.
     * Hasil dibatasi maksimal MAX_ASSET_CANDIDATES_FOR_AI (80), jauh lebih
     * hemat token dibanding pendekatan limit(300) tanpa filter sebelumnya.
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu (opsional)
     * @return array    Array asosiatif kandidat asset dengan kunci: id, tech_ident_no, description, functional_loc, area_code, sub_area_name
     */
    protected function prefilterAssetCandidates(string $text, ?int $areaId = null): array
    {
        // Ambil daftar kode area (dan varian pendeknya) untuk dikecualikan dari kandidat
        // keyword pencarian equipment (kode area bukan bagian dari tech_ident_no/description)
        $areaCodes      = Area::pluck('code')->map(fn($c) => strtoupper($c))->toArray();
        $shortAreaCodes = [];
        foreach ($areaCodes as $ac) {
            if (preg_match('/^([A-Z]+)0(\d)$/', $ac, $m)) {
                $shortAreaCodes[] = $m[1] . $m[2];
            }
        }
        $allAreaVariants = array_merge($areaCodes, $shortAreaCodes);

        $keywords = $this->extractSearchKeywords($text, $allAreaVariants);

        if (empty($keywords)) {
            // Fallback: tidak ada keyword valid, kembalikan 80 asset pertama
            // yang punya tech_ident_no (mirip behavior lama tapi tetap dibatasi)
            $query = Asset::whereNotNull('tech_ident_no');
            if ($areaId) {
                $query->where('area_id', $areaId);
            }
            return $query->limit(self::MAX_ASSET_CANDIDATES_FOR_AI)
                ->get(['id', 'tech_ident_no', 'description', 'functional_loc', 'area_id', 'sub_area_id'])
                ->map(function ($a) {
                    return [
                        'id'             => $a->id,
                        'tech_ident_no'  => $a->tech_ident_no ?? '',
                        'description'    => $a->description ?? '',
                        'functional_loc' => $a->functional_loc ?? '',
                        'area_code'      => $a->area->code ?? null,
                        'sub_area_name'  => $a->subArea->name ?? null,
                    ];
                })
                ->toArray();
        }

        // Bangun query dengan OR WHERE LIKE untuk setiap keyword
        // Cari di tech_ident_no DAN description
        $baseQuery = Asset::whereNotNull('tech_ident_no');
        if ($areaId) {
            $baseQuery->where('area_id', $areaId);
        }

        $baseQuery->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '%' . $keyword . '%';
                $q->orWhere('tech_ident_no', 'LIKE', $pattern)
                  ->orWhere('description', 'LIKE', $pattern);
            }
        });

        $results = $baseQuery
            ->limit(self::MAX_ASSET_CANDIDATES_FOR_AI)
            ->get(['id', 'tech_ident_no', 'description', 'functional_loc', 'area_id', 'sub_area_id']);

        // Jika hasil terlalu sedikit (< 5), perluas pencarian dengan keyword lebih pendek
        if ($results->count() < 5 && count($keywords) > 0) {
            $extraQuery = Asset::whereNotNull('tech_ident_no');
            if ($areaId) {
                $extraQuery->where('area_id', $areaId);
            }

            // Gunakan 3 kata kunci pertama yang paling panjang sebagai perluasan
            $topKeywords = array_slice($keywords, 0, 3);
            $extraQuery->where(function ($q) use ($topKeywords) {
                foreach ($topKeywords as $keyword) {
                    $pattern = '%' . $keyword . '%';
                    $q->orWhere('tech_ident_no', 'LIKE', $pattern)
                      ->orWhere('description', 'LIKE', $pattern);
                }
            });

            $extraResults = $extraQuery
                ->limit(self::MAX_ASSET_CANDIDATES_FOR_AI - $results->count())
                ->get(['id', 'tech_ident_no', 'description', 'functional_loc', 'area_id', 'sub_area_id']);

            // Gabung hasil, hilangkan duplikat berdasarkan ID
            $existingIds   = $results->pluck('id')->toArray();
            $mergedResults = $results->merge(
                $extraResults->filter(fn($a) => !in_array($a->id, $existingIds, true))
            );
        } else {
            $mergedResults = $results;
        }

        return $mergedResults
            ->map(function ($a) {
                return [
                    'id'             => $a->id,
                    'tech_ident_no'  => $a->tech_ident_no ?? '',
                    'description'    => $a->description ?? '',
                    'functional_loc' => $a->functional_loc ?? '',
                    'area_code'      => $a->area->code ?? null,
                    'sub_area_name'  => $a->subArea->name ?? null,
                ];
            })
            ->toArray();
    }

    /**
     * Pencarian lokasi/area kerja via AI — lapis ketiga, dijalankan setelah
     * regex (TechIdentSearchService) DAN pencarian equipment via AI
     * (searchEquipmentWithAi()) sama-sama tidak menemukan hasil.
     *
     * Method ini khusus untuk laporan yang menyebut nama lokasi/pekerjaan
     * area (mis. "penggantian lantai lab") tanpa merujuk ke alat tertentu.
     * Kandidat functional_locations dikirim dalam bentuk pre-filtered
     * (bukan dump seluruh tabel), hanya mencakup level Area dan Section
     * karena level Site/Departemen terlalu umum untuk jadi lokasi laporan.
     *
     * Mengembalikan hasil dengan format konsisten dengan searchEquipmentWithAi()
     * (status, pass, confidence, candidates) agar mudah ditangani secara
     * seragam oleh ReportWizardService.
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu jika sudah diketahui
     * @return array|null Hasil pencarian (status, candidates, confidence) atau null jika gagal
     */
    protected function searchLocationWithAi(string $text, ?int $areaId = null): ?array
    {
        $provider = $this->getBestProvider();
        if (!$provider) {
            Log::info('AI Service: No provider available for location search');
            return null;
        }

        try {
            // Pre-filter kandidat lokasi berdasarkan keyword dari teks
            $candidates = $this->prefilterLocationCandidates($text, $areaId);

            if (empty($candidates)) {
                Log::info('AI Service: Location pre-filter returned zero candidates, skip AI call');
                return null;
            }

            $locations = collect($candidates)
                ->map(function ($l) {
                    $areaCode    = $l['area_code']     ?? '-';
                    $subAreaName = $l['sub_area_name'] ?? '-';
                    $hierarchy   = $l['hierarchy']     ?? '';
                    $hierarchy   = $hierarchy !== '' ? " | Hierarki:{$hierarchy}" : '';
                    return "ID:{$l['id']} | Kode:{$l['code']} | Nama:{$l['name']} | Level:{$l['level_label']} | Area:{$areaCode} | SubArea:{$subAreaName}{$hierarchy}";
                })
                ->implode("\n");

            Log::info('AI Service: Location search pre-filtered candidates', [
                'total_funclocs_in_db' => FunctionalLocation::count(),
                'prefiltered_count'    => count($candidates),
                'area_id'              => $areaId,
            ]);

            $prompt = <<<PROMPT
Kamu adalah asisten pencarian lokasi/area kerja di pabrik oleochemical.

Teks laporan teknisi: "{$text}"

DAFTAR KANDIDAT LOKASI (functional location):
{$locations}

KONTEKS:
Teks ini sudah dicoba dicocokkan sebagai kode/deskripsi ALAT (equipment) dan TIDAK ditemukan.
Sekarang cek apakah teks ini sebenarnya melaporkan pekerjaan AREA/LOKASI, bukan alat tertentu.
Contoh laporan area/lokasi: "penggantian lantai lab", "pengecatan dinding gudang",
"perbaikan atap workshop", "pembersihan area parkir".

TUGAS KAMU:
1. Baca teks laporan baik-baik, cari nama lokasi/ruangan/area yang disebut.
   Lokasi bisa disebut secara eksplisit, misalnya "di Main Office", "di DB2",
   "di area lab", "gudang", "workshop". Perhatikan juga kode yang mirip
   TechIdentNo tapi tidak cocok dengan alat — bisa jadi itu kode lokasi.
2. Cocokkan dengan Kode, Nama, atau Hierarki pada daftar kandidat di atas.
   Kolom Hierarki berisi jalur penuh (mis. "EPE > HR & General Affairs > Main Office"),
   sehingga kecocokan bisa ditemukan walau teknisi hanya menyebut sebagian nama.
3. Jika TIDAK YAKIN (confidence < 60), lebih baik mengembalikan candidates kosong daripada menebak.
4. Jika YAKIN (confidence >= 60), isi candidates dengan maksimal 3 kandidat paling cocok,
   diurutkan dari yang paling yakin.

ATURAN OUTPUT:
- candidates: array of objects dengan field "id" (numeric, ID functional_location),
  "code", "name", "confidence" (0-100 individual)
- message: penjelasan singkat dalam Bahasa Indonesia kenapa kandidat tersebut dipilih

Balas HANYA JSON (tanpa markdown, tanpa tag):
{
  "status": "auto_accept|confirm|no_match",
  "confidence": 0-100,
  "candidates": [
    {"id": 123, "code": "xxx", "name": "xxx", "confidence": 90}
  ],
  "message": "penjelasan singkat dalam Bahasa Indonesia"
}
PROMPT;

            $response = $this->callGroq($provider, $prompt);
            if ($response) {
                $cleaned = $this->stripJsonFence($response);
                $parsed  = json_decode($cleaned, true);

                if ($parsed && isset($parsed['status'])) {
                    // Hanya kembalikan hasil jika AI cukup yakin (confidence >= 60)
                    if (($parsed['confidence'] ?? 0) >= 60 && !empty($parsed['candidates'])) {
                        Log::info('AI Service: Location search found candidates', [
                            'confidence'      => $parsed['confidence'],
                            'candidate_count' => count($parsed['candidates']),
                        ]);

                        // Gabungkan hasil AI (id/code/name/confidence) dengan data
                        // lengkap dari kandidat pre-filter (area_id, sub_area_id, dst.)
                        // agar ReportWizardService punya semua data yang dibutuhkan
                        // untuk mengisi area_id/funcloc_id tanpa query ulang.
                        $candidatesById = collect($candidates)->keyBy('id');

                        $mergedCandidates = collect($parsed['candidates'])
                            ->map(function ($c) use ($candidatesById) {
                                $full = $candidatesById->get($c['id'] ?? null);
                                if (!$full) {
                                    return null;
                                }
                                return [
                                    'id'            => $full['id'],
                                    'code'          => $full['code'],
                                    'name'          => $full['name'],
                                    'level'         => $full['level'],
                                    'level_label'   => $full['level_label'],
                                    'area_id'       => $full['area_id'],
                                    'sub_area_id'   => $full['sub_area_id'],
                                    'area_code'     => $full['area_code'],
                                    'sub_area_name' => $full['sub_area_name'],
                                    'hierarchy'     => $full['hierarchy'] ?? '',
                                ];
                            })
                            ->filter()
                            ->values()
                            ->toArray();

                        if (empty($mergedCandidates)) {
                            Log::warning('AI Service: Location search candidates did not match any pre-filtered ID');
                            return null;
                        }

                        return [
                            'status'     => $parsed['status'] === 'auto_accept' ? 'auto_accept' : 'confirm',
                            'pass'       => 'ai_location',
                            'confidence' => $parsed['confidence'] ?? 70,
                            'candidates' => $mergedCandidates,
                            'ai_message' => $parsed['message'] ?? '',
                        ];
                    }

                    Log::info('AI Service: Location search low confidence or empty candidates', [
                        'confidence'      => $parsed['confidence'] ?? 0,
                        'candidate_count' => count($parsed['candidates'] ?? []),
                    ]);
                } else {
                    Log::warning('AI Service: Failed to parse location search JSON', [
                        'raw'     => substr($response, 0, 200),
                        'cleaned' => substr($cleaned, 0, 200),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("AI Service location search error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Pre-filter kandidat functional_location dari teks laporan sebelum
     * dikirim ke prompt AI.
     *
     * Hanya mengambil node level Area dan Section (LEVEL_AREA, LEVEL_SECTION)
     * karena level Site/Departemen terlalu umum untuk jadi lokasi laporan
     * spesifik. Mencari kata kunci dari teks (via extractSearchKeywords())
     * di kolom code ATAU name. Hasil dibatasi maksimal
     * MAX_LOCATION_CANDIDATES_FOR_AI (60).
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu (opsional)
     * @return array    Array asosiatif kandidat lokasi dengan kunci: id, code, name, level, level_label, area_id, sub_area_id, area_code, sub_area_name
     */
    protected function prefilterLocationCandidates(string $text, ?int $areaId = null): array
    {
        $keywords = $this->extractSearchKeywords($text);

        $relevantLevels = [FunctionalLocation::LEVEL_AREA, FunctionalLocation::LEVEL_SECTION];

        // Sesi 7 — deteksi area yang disebut eksplisit di teks (kode/nama,
        // termasuk varian tanpa leading zero seperti "BD1" untuk "BD01").
        // Area terdeteksi dipakai untuk memprioritaskan node funcloc di
        // bawahnya sekaligus memberi konteks tambahan ke AI, sehingga lokasi
        // seperti "di DB2 tu di Main Office" tetap menemukan kandidat walau
        // teknisi memakai istilah lapangan yang tidak persis sama dengan kode.
        $detectedArea = method_exists($this, 'detectArea') ? $this->detectArea($text) : null;

        // Area id yang relevan: areaId dari pemanggil (state wizard) menang;
        // jika tidak ada, gunakan area hasil deteksi teks.
        $priorityAreaId = $areaId ?? ($detectedArea['id'] ?? null);

        $mapResults = function ($collection) use ($priorityAreaId) {
            return $collection->map(function (FunctionalLocation $fl) use ($priorityAreaId) {
                return [
                    'id'            => $fl->id,
                    'code'          => $fl->code ?? '',
                    'name'          => $fl->name ?? '',
                    'level'         => $fl->level,
                    'level_label'   => $fl->level_label,
                    'area_id'       => $fl->area_id,
                    'sub_area_id'   => $fl->sub_area_id,
                    'area_code'     => $fl->area->code ?? null,
                    'sub_area_name' => $fl->subArea->name ?? null,
                    'hierarchy'     => $this->buildLocationHierarchyLabel($fl),
                    'area_match'    => $priorityAreaId !== null && (int) $fl->area_id === (int) $priorityAreaId,
                ];
            })->toArray();
        };

        if (empty($keywords)) {
            // Fallback: tidak ada keyword valid, kembalikan node level Area/Section
            // pertama yang aktif, tetap dibatasi jumlahnya.
            $query = FunctionalLocation::active()
                ->with('parent.parent.parent')
                ->whereIn('level', $relevantLevels);
            if ($areaId) {
                $query->where('area_id', $areaId);
            } elseif ($detectedArea) {
                // Area terdeteksi dari teks — batasi ke area itu agar kandidat
                // tidak menebar ke seluruh pabrik saat tidak ada keyword.
                $this->scopeByDetectedArea($query, $detectedArea);
            }

            return $mapResults(
                $query->limit(self::MAX_LOCATION_CANDIDATES_FOR_AI)->get()
            );
        }

        $baseQuery = FunctionalLocation::active()
            ->with('parent.parent.parent')
            ->whereIn('level', $relevantLevels);
        if ($areaId) {
            $baseQuery->where('area_id', $areaId);
        } elseif ($detectedArea) {
            $this->scopeByDetectedArea($baseQuery, $detectedArea);
        }

        $baseQuery->where(function ($q) use ($keywords, $priorityAreaId) {
            foreach ($keywords as $keyword) {
                $pattern = '%' . $keyword . '%';
                $q->orWhere('code', 'LIKE', $pattern)
                  ->orWhere('name', 'LIKE', $pattern);
            }

            // Area terdeteksi dari teks: sertakan seluruh node di area tersebut
            // sebagai kandidat walau tidak match keyword (mis. "Main Office"
            // disebut bersama kode area lain yang tidak ada di nama funcloc).
            if ($priorityAreaId !== null) {
                $q->orWhere('area_id', $priorityAreaId);
            }
        });

        $results = $baseQuery->limit(self::MAX_LOCATION_CANDIDATES_FOR_AI)->get();

        // Jika hasil terlalu sedikit (< 5), perluas pencarian dengan keyword lebih pendek
        if ($results->count() < 5 && count($keywords) > 0) {
            $extraQuery = FunctionalLocation::active()
                ->with('parent.parent.parent')
                ->whereIn('level', $relevantLevels);
            if ($areaId) {
                $extraQuery->where('area_id', $areaId);
            } elseif ($detectedArea) {
                $this->scopeByDetectedArea($extraQuery, $detectedArea);
            }

            $topKeywords = array_slice($keywords, 0, 3);
            $extraQuery->where(function ($q) use ($topKeywords) {
                foreach ($topKeywords as $keyword) {
                    $pattern = '%' . $keyword . '%';
                    $q->orWhere('code', 'LIKE', $pattern)
                      ->orWhere('name', 'LIKE', $pattern);
                }
            });

            $extraResults = $extraQuery
                ->limit(self::MAX_LOCATION_CANDIDATES_FOR_AI - $results->count())
                ->get();

            $existingIds   = $results->pluck('id')->toArray();
            $mergedResults = $results->merge(
                $extraResults->filter(fn($fl) => !in_array($fl->id, $existingIds, true))
            );
        } else {
            $mergedResults = $results;
        }

        // Urutkan kandidat: yang area_id-nya sama dengan area terdeteksi
        // didahulukan (area_match = true), sisanya menyusul.
        $sortedResults = $mergedResults->sortByDesc(fn(FunctionalLocation $fl) =>
            $priorityAreaId !== null && (int) $fl->area_id === (int) $priorityAreaId
        );

        return $mapResults($sortedResults->values());
    }

    /**
     * Bangun label hierarki (breadcrumb) untuk satu node FunctionalLocation,
     * misalnya "EPE > HR & General Affairs > Main Office". Dipakai di prompt
     * AI pencarian lokasi dan di pesan konfirmasi ke teknisi, agar posisi
     * node di dalam pohon lokasi langsung terlihat.
     *
     * @param  FunctionalLocation $fl Node FunctionalLocation
     * @return string Label hierarki lengkap dari root hingga node ini
     */
    protected function buildLocationHierarchyLabel(FunctionalLocation $fl): string
    {
        $labels = [];

        if ($fl->name) {
            $labels[] = $fl->name;
        } elseif ($fl->code) {
            $labels[] = $fl->code;
        }

        $parent = $fl->parent;
        while ($parent !== null) {
            $label = $parent->name ?: $parent->code;
            array_unshift($labels, $label);
            $parent = $parent->parent;
        }

        return implode(' > ', $labels);
    }

    /**
     * Batasi query pencarian lokasi ke area yang terdeteksi dari teks.
     *
     * Sesi 7 audit: sebagian node FunctionalLocation punya area_id NULL
     * (55 dari 98) walaupun kode-nya jelas mengandung segmen kode area
     * (mis. "EPE-PROD-TF01-6010" berisi segmen "TF01"). Backfill migration
     * sudah mengisi area_id untuk 42 node tersebut, tetapi agar pencarian
     * tetap berfungsi untuk node baru yang belum ter-backfill, filter ini
     * memakai dua sinyal sekaligus:
     *   - area_id = area yang terdeteksi, ATAU
     *   - code mengandung kode area sebagai segmen utuh (dipisah tanda "-")
     *     di posisi manapun (awal, tengah, akhir).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query       Query funcloc
     * @param  array                                  $detectedArea Hasil detectArea(): ['id'=>int, 'code'=>string]
     * @return void
     */
    protected function scopeByDetectedArea($query, array $detectedArea): void
    {
        $areaId     = $detectedArea['id'] ?? null;
        $areaCode   = strtoupper((string) ($detectedArea['code'] ?? ''));

        $query->where(function ($q) use ($areaId, $areaCode) {
            if ($areaId !== null) {
                $q->where('area_id', $areaId);
            }

            if ($areaCode !== '') {
                // Segmen utuh dipisah tanda "-", bisa di awal, tengah, atau akhir.
                // LIKE dipakai (bukan REGEXP) agar portabel MySQL & SQLite (test).
                $q->orWhere('code', 'LIKE', $areaCode . '-%')
                  ->orWhere('code', 'LIKE', '%-' . $areaCode . '-%')
                  ->orWhere('code', 'LIKE', '%-' . $areaCode);
            }
        });
    }

    /**
     * Kirim prompt ke Groq API menggunakan provider yang dipilih.
     *
     * Mencatat penggunaan token (harian & bulanan) dan menyimpan log ke AiUsageLog.
     * Menandai provider sebagai 'exhausted' jika mendapat respons HTTP 429.
     *
     * Catatan bug yang sudah diperbaiki:
     *   BUG 2 — sebelumnya memakai $provider->api_key_encrypted langsung sebagai Bearer token.
     *            Jika nilai tersimpan ter-enkripsi (Laravel Crypt), request selalu 401 Unauthorized.
     *            Diperbaiki dengan menggunakan accessor $provider->api_key yang auto-decrypt.
     *
     * @param AiProvider $provider Provider yang akan digunakan
     * @param string     $prompt   Prompt yang dikirim ke model
     * @return string|null Konten respons dari model, atau null jika gagal
     */
    protected function callGroq(AiProvider $provider, string $prompt): ?string
    {
        $startTime    = microtime(true);
        $responseTime = 0;
        $tokensUsed   = 0;
        $status       = 'error';
        $errorMessage = null;

        try {
            $endpoint = $provider->endpoint_url ?? 'https://api.groq.com/openai/v1/chat/completions';
            $model    = $provider->model         ?? self::DEFAULT_GROQ_MODEL;

            // BUG 2 FIX — gunakan accessor api_key (bukan api_key_encrypted)
            // agar nilai yang terenkripsi di-decrypt terlebih dahulu.
            $apiKey = $provider->api_key;

            if (empty($apiKey)) {
                Log::warning("AI Service: API key kosong untuk provider [{$provider->name}]");
                return null;
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($endpoint, [
                    'model'    => $model,
                    'messages' => [
                        [
                            'role'    => 'system',
                            'content' => 'Kamu adalah asisten analis laporan maintenance. Balas HANYA dalam format JSON.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.1,
                    'max_tokens'  => 600,
                ]);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $content    = $response->json()['choices'][0]['message']['content'] ?? null;
                $tokensUsed = $response->json()['usage']['total_tokens'] ?? 0;
                $status     = 'success';

                Log::info("AI Groq response received", [
                    'provider' => $provider->name,
                    'length'   => strlen($content ?? ''),
                    'tokens'   => $tokensUsed,
                    'ms'       => $responseTime,
                ]);

                // Perbarui statistik penggunaan provider
                $provider->increment('tokens_used_today', $tokensUsed);
                $provider->increment('tokens_used_month', $tokensUsed);
                $provider->increment('request_count_24h');
                $provider->update(['last_used_at' => now()]);

                // Simpan log penggunaan
                try {
                    AiUsageLog::create([
                        'provider_id'      => $provider->id,
                        'tokens_used'      => $tokensUsed,
                        'request_type'     => 'analyze_report',
                        'response_time_ms' => $responseTime,
                        'status'           => 'success',
                    ]);
                } catch (\Exception $e) {
                    // Abaikan error log usage agar tidak mengganggu alur utama
                }

                return $content;
            }

            // Tandai provider exhausted jika quota habis (HTTP 429)
            if ($response->status() === 429) {
                $provider->update(['status' => 'exhausted']);
                Log::warning("AI Service: Provider [{$provider->name}] quota exhausted (429)");
            }

            $errorMessage = substr($response->body(), 0, 300);
            Log::warning("Groq API error: " . $response->status() . " - " . $errorMessage);

        } catch (\Exception $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $errorMessage = $e->getMessage();
            Log::error("Groq call error: " . $errorMessage);
        }

        // Simpan log error penggunaan
        try {
            AiUsageLog::create([
                'provider_id'      => $provider->id,
                'tokens_used'      => $tokensUsed,
                'request_type'     => 'analyze_report',
                'response_time_ms' => $responseTime,
                'status'           => $status,
                'error_message'    => $errorMessage,
            ]);
        } catch (\Exception $e) {
            // Abaikan
        }

        return null;
    }

    /**
     * Hapus markdown code fence yang mungkin membungkus respons Groq.
     *
     * Pola yang ditangani:
     *   ```json { ... } ```  menjadi  { ... }
     *   ``` { ... } ```      menjadi  { ... }
     *
     * @param string $raw Respons mentah dari Groq
     * @return string Konten JSON yang sudah bersih
     */
    protected function stripJsonFence(string $raw): string
    {
        $cleaned = trim($raw);

        // Hapus opening fence: ```json atau ```
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);

        // Hapus closing fence: ```
        $cleaned = preg_replace('/\s*```\s*$/i', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Ambil provider AI terbaik yang tersedia (status healthy, masih ada kuota).
     *
     * Urutan seleksi berdasarkan kolom priority (ASC) — nilai terkecil paling diprioritaskan.
     * Provider dilewati jika kuota harian atau bulanan sudah habis.
     *
     * @return AiProvider|null Provider yang siap digunakan, atau null jika semua habis/tidak sehat
     */
    protected function getBestProvider(): ?AiProvider
    {
        $providers = AiProvider::healthy()
            ->byPriority()
            ->get();

        foreach ($providers as $provider) {
            // Cek apakah masih punya kuota harian
            if ($provider->daily_token_limit > 0 && $provider->tokens_used_today >= $provider->daily_token_limit) {
                continue;
            }
            // Cek kuota bulanan
            if ($provider->monthly_token_limit > 0 && $provider->tokens_used_month >= $provider->monthly_token_limit) {
                continue;
            }

            return $provider;
        }

        return null;
    }
}
