<?php

namespace App\Services;

use App\Models\AiAlias;
use App\Models\AiProvider;
use App\Models\Area;
use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    /**
     * Analyze report text using AI (Groq) to detect area, equipment, and report type.
     * Falls back to keyword matching if AI unavailable.
     */
    public function analyzeReportText(string $text): array
    {
        $result = [
            'report_type'             => 'general',
            'suggested_area'          => null,
            'suggested_equipment'     => null,
            'detected_area'           => null,
            'detected_equipment'      => null,
            'detected_equipment_id'   => null,
            'confidence'              => 0,
            'message'                 => '',
            'needs_clarification'     => false,
            'clarification_questions' => [],
            'parsed_duration_minutes' => null,
            'parsed_root_cause'       => null,
        ];

        // Parsing durasi pekerjaan & root cause dari teks awal (Step 4 & Step 5 wizard).
        // Dijalankan terpisah dari deteksi area/equipment di bawah, dan tidak bergantung
        // pada AI provider, supaya tetap berfungsi walau provider sedang exhausted/down.
        $result['parsed_duration_minutes'] = $this->parseWorkDurationMinutes($text);
        $result['parsed_root_cause']       = $this->parseRootCauseHint($text);

        // 1. Coba AI provider (Groq/LLM)
        $aiResult = $this->analyzeWithAi($text);
        if ($aiResult !== null) {
            return array_merge($result, $aiResult);
        }

        // 2. Fallback: keyword matching
        $kwResult = $this->analyzeWithKeywords($text);
        return array_merge($result, $kwResult);
    }

    // =========================================================
    // BUG 1 FIX + BUG 5 FIX
    // analyzeWithAi:
    //   BUG 1 — relasi $a->area dan $a->subArea bisa null,
    //           menyebabkan "Trying to get property of non-object"
    //           → pakai null-safe operator dan nilai default.
    //   BUG 5 — Groq kadang membalas dengan ```json ... ```,
    //           json_decode gagal → strip markdown fence sebelum decode.
    // =========================================================
    /**
     * Analyze using AI provider (Groq).
     * TechIdentNo dan FunctionalLoc adalah kunci utama.
     * Description hanya membantu, bukan acuan.
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

            // BUG 1 FIX — gunakan null-safe operator (?->) dan nilai default
            // agar tidak crash ketika asset tidak memiliki relasi area / subArea.
            $equipments = Asset::with(['area', 'subArea'])
                ->whereNotNull('tech_ident_no')
                ->limit(300)
                ->get()
                ->map(function ($a) {
                    $areaCode   = $a->area?->code    ?? '-';
                    $subAreaName = $a->subArea?->name ?? '-';
                    return "ID:{$a->id} | TechIdentNo:{$a->tech_ident_no} | FuncLoc:{$a->functional_loc} | Desc:{$a->description} | Area:{$areaCode} | SubArea:{$subAreaName}";
                })
                ->implode("\n");

            $prompt = <<<PROMPT
Kamu adalah asisten analis laporan maintenance pabrik oleochemical.

Teks laporan: "{$text}"

DAFTAR AREA (kode dan nama):
{$areas}

DAFTAR EQUIPMENT (TechIdentNo adalah KUNCI UTAMA untuk identifikasi alat):
{$equipments}

ATURAN PENTING:
1. Teknisi selalu menyebut TechIdentNo alat, BUKAN equipment_no atau description.
2. TechIdentNo bisa berupa: "6163V7", "2-6153P1", "AC-TF-1-1", "V7", "6153P1", dll.
3. Jika teks mengandung angka/huruf yang mirip bagian dari TechIdentNo (misal "V7", "6163"), cari partial match di daftar equipment.
4. Jika kata "V7" disebut, cari TechIdentNo yang mengandung "V7" di tabel equipment.

TUGAS:
1. Cari AREA kerja: cocokkan teks dengan kode area (BD1, BD01, RG1, RG01, EPE, TF1, MD1, EN1, UT1, dll). Jika ada yang cocok, isi detected_area dengan kode areanya. Jika tidak ada, null.
2. Cari EQUIPMENT: cocokkan teks dengan TechIdentNo (prioritas utama) atau FunctionalLoc. Cari partial match jika user menyebut kode parsial seperti "V7", "6163", "P1". Jika user menyebut "6163V7", cari yang cocok dengan TechIdentNo yang mengandung "6163V7".
3. Jika equipment ditemukan, isi detected_equipment_id dengan ID numeric dari equipment tersebut.
4. Tentukan jenis laporan: equipment_repair (jika ada equipment disebut), area_work (jika hanya area/pekerjaan bangunan), atau general.
5. confidence: 0-100. Beri tinggi (80-100) jika equipment cocok exact / jelas. Rendah (<40) jika hanya partial match area.
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

    // =========================================================
    // BUG 2 FIX
    // callGroq:
    //   Sebelumnya memakai $provider->api_key_encrypted langsung
    //   sebagai Bearer token. Jika nilai tersimpan ter-enkripsi
    //   (Laravel Crypt), request selalu 401 Unauthorized.
    //   → Pakai accessor $provider->api_key yang auto-decrypt
    //     (didefinisikan di AiProvider model).
    // =========================================================
    /**
     * Call Groq API menggunakan provider yang dipilih.
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
            $model    = $provider->model         ?? 'llama-3.3-70b-versatile';

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

            $responseTime = (int)((microtime(true) - $startTime) * 1000);

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

                // Update provider usage stats
                $provider->increment('tokens_used_today', $tokensUsed);
                $provider->increment('tokens_used_month', $tokensUsed);
                $provider->increment('request_count_24h');
                $provider->update(['last_used_at' => now()]);

                // Log usage
                try {
                    \App\Models\AiUsageLog::create([
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
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $errorMessage = $e->getMessage();
            Log::error("Groq call error: " . $errorMessage);
        }

        // Log error usage
        try {
            \App\Models\AiUsageLog::create([
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

    // =========================================================
    // BUG 5 FIX — Helper: strip markdown code fence dari respons
    // =========================================================
    /**
     * Hapus markdown code fence yang mungkin dibungkus Groq:
     *   ```json { ... } ```  →  { ... }
     *   ``` { ... } ```      →  { ... }
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
     * Fallback: keyword-based analysis
     * Prioritas: Area code dulu → TechIdentNo (bisa parsial) → Alias
     * Alur: deteksi area dulu untuk mempersempit pencarian equipment
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

        // 1. Cari area dari teks TERLEBIH DAHULU (untuk mempersempit pencarian equipment)
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

            // Equipment ketemu → override area dengan area dari equipment (lebih akurat)
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
                $result['confidence']          = max($result['confidence'], (int)($aliasMatches['asset']->confidence * 100));
            }
            if (!empty($aliasMatches['area'])) {
                $result['detected_area']  = $aliasMatches['area']->area?->code ?? $aliasMatches['area']->alias_text;
                $result['suggested_area'] = $aliasMatches['area']->area?->code;
                $result['confidence']     = max($result['confidence'], (int)($aliasMatches['area']->confidence * 100));
            }
        }

        // 4. Decision: perlu klarifikasi atau tidak
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
     * Deteksi area dari teks — cari kode area yang disebut.
     * Support: "BD01", "BD1" (tanpa leading zero), "RG1", "RG01", "EPE", "TF1", dll.
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

        // PASS 2: Handle kode area yang ditulis TANPA leading zero
        // Contoh: "BD1" harus cocok dengan kode "BD01"
        foreach ($sortedAreas as $area) {
            $code = strtoupper($area->code);

            // Apakah code adalah format dengan leading zero? Misal "BD01"
            if (preg_match('/^([A-Z]+)0(\d)$/', $code, $m)) {
                $shortCode = $m[1] . $m[2]; // "BD1"
                if (str_contains($textUpper, $shortCode)) {
                    // Pastikan bukan bagian dari kode lain yang lebih panjang
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
                // Cek apakah teks menyebut "BD" diikuti digit (tanpa 0)
                if (preg_match('/\b' . preg_quote($prefixOnly, '/') . '(\d)\b/', $textUpper, $dm)) {
                    $shortCode = $prefixOnly . $dm[1];
                    if ($shortCode === $m[1] . $m[2]) {
                        return ['id' => $area->id, 'code' => $area->code, 'name' => $area->name];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Cari asset berdasarkan TechIdentNo — support partial match dengan prioritas bertingkat.
     *
     * Prioritas pencarian:
     * 1. Exact match
     * 2. TechIdentNo STARTS WITH candidate (prefix)
     * 3. Candidate STARTS WITH TechIdentNo (suffix)
     * 4. Partial match (contains) — hanya untuk kode yang cukup unik
     *
     * @param string   $text   Teks laporan
     * @param int|null $areaId Batasi pencarian ke area tertentu jika diketahui
     */
    protected function detectAssetByTechIdent(string $text, ?int $areaId = null): ?array
    {
        $text = strtoupper(trim($text));

        // Ekstrak semua kata/kode potensial dari teks
        // Support format: "6163V7", "2-6153P1", "AC-TF-1-1", "V7"
        preg_match_all('/[A-Z0-9][A-Z0-9\.\-\/]+[A-Z0-9]|[A-Z0-9]{2,}/i', $text, $matches);
        $words = array_unique($matches[0]);

        // Filter kata yang relevan (min 2 karakter, max 30)
        $candidates = array_filter($words, fn($w) => strlen($w) >= 2 && strlen($w) <= 30);

        // Urutkan: yang lebih panjang/kompleks lebih diprioritaskan
        usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));

        // Filter kode AREA dari kandidat — jangan jadikan area code sebagai equipment
        $areaCodes      = Area::pluck('code')->map(fn($c) => strtoupper($c))->toArray();
        $shortAreaCodes = [];
        foreach ($areaCodes as $ac) {
            if (preg_match('/^([A-Z]+)0(\d)$/', $ac, $m)) {
                $shortAreaCodes[] = $m[1] . $m[2]; // "BD1" dari "BD01"
            }
        }
        $allAreaVariants = array_merge($areaCodes, $shortAreaCodes);

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

        // PRIORITAS 4: Partial match (contains) untuk kode yang cukup unik
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
     * Helper: cari first match dari query dengan kondisi
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
     * Deteksi tipe laporan dari teks berdasarkan kata kunci
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
     * Cek apakah teks mengandung alias yang sudah dipelajari
     */
    protected function checkAliases(string $text): array
    {
        $result    = ['asset' => null, 'area' => null];
        $textUpper = strtoupper(trim($text));

        // Ambil semua alias yang aktif (confirmed atau pending)
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
     * Ambil provider AI terbaik yang tersedia (healthy, token masih ada).
     * Prioritas berdasarkan kolom priority (ASC).
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

    // =========================================================
    // F2 — TAMBAHAN: parsing waktu pengerjaan & root cause
    // =========================================================
    /**
     * Coba ekstrak estimasi durasi pekerjaan (dalam menit) dari teks awal teknisi.
     * Pola yang dikenali: "2 jam", "1,5 jam", "90 menit", "2 jam 30 menit".
     * Hasil dipakai untuk pre-fill Step 4 (Waktu Pengerjaan) wizard agar teknisi
     * tidak perlu mengetik ulang jika durasi sudah disebut di pesan pertama.
     */
    protected function parseWorkDurationMinutes(string $text): ?int
    {
        $textLower    = strtolower($text);
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

        return $found ? $totalMinutes : null;
    }

    /**
     * Coba ekstrak potongan kalimat yang mengindikasikan root cause dari teks awal.
     * Mengenali pola umum: "karena ...", "akibat ...", "disebabkan ...", "penyebab ...".
     * Hasil ini hanya hint awal — teknisi tetap dikonfirmasi/diminta lengkapi di Step 5,
     * sesuai aturan root_cause wajib diisi minimal 3 karakter.
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
