<?php

namespace App\Services\Traits;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Area;
use App\Models\Asset;
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
 *   - analyzeWithAi()    : Analisis teks laporan via AI provider yang dipilih
 *   - callGroq()         : Kirim request ke Groq API dan catat penggunaan token
 *   - stripJsonFence()   : Hapus markdown code fence dari respons Groq
 *   - getBestProvider()  : Pilih provider AI terbaik yang tersedia (healthy + ada kuota)
 */
trait AiProviderCallerTrait
{
    /**
     * Analisis teks laporan menggunakan provider AI (Groq/LLM).
     *
     * Membangun prompt dengan daftar area dan equipment dari database,
     * lalu meneruskannya ke Groq. Mengembalikan null jika tidak ada provider
     * yang tersedia atau respons tidak dapat diparse.
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

            // BUG 1 FIX — gunakan null-safe operator (?->) dan nilai default
            // agar tidak crash ketika asset tidak memiliki relasi area / subArea.
            $equipments = Asset::with(['area', 'subArea'])
                ->whereNotNull('tech_ident_no')
                ->limit(300)
                ->get()
                ->map(function ($a) {
                    $areaCode    = $a->area?->code    ?? '-';
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
