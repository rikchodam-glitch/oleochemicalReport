<?php

namespace App\Http\Controllers;

use App\Models\AiAlias;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiProviderController extends Controller
{
    /**
     * Tampilkan halaman daftar AI Provider beserta dashboard penggunaan.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $providers = AiProvider::orderBy('priority')->get();

        $healthyProviders = AiProvider::where('status', 'healthy')->get();
        $sisaHarian = $healthyProviders->sum(function ($p) {
            return max(0, $p->daily_token_limit - $p->tokens_used_today);
        });

        $stats = [
            'total_providers'   => AiProvider::count(),
            'healthy_providers' => $healthyProviders->count(),
            'requests_24h'      => AiUsageLog::lastHours(24)->count(),
            'success_24h'       => AiUsageLog::lastHours(24)->success()->count(),
            'error_24h'         => AiUsageLog::lastHours(24)->error()->count(),
            'total_tokens_24h'  => AiUsageLog::lastHours(24)->sum('tokens_used'),
            'avg_response_ms'   => (int) AiUsageLog::lastHours(24)->avg('response_time_ms'),
            'sisa_harian'       => $sisaHarian,
        ];

        $recentLogs = AiUsageLog::with('provider')
            ->latest()
            ->take(30)
            ->get();

        $statsByType = AiUsageLog::statsByRequestType(24);

        $statsPerProvider = AiUsageLog::statsPerProvider24h();

        $pendingAliases = AiAlias::with(['asset', 'area', 'technician'])
            ->where('status', 'pending')
            ->latest()
            ->take(20)
            ->get();

        $aliases = AiAlias::with(['asset', 'area'])
            ->latest()
            ->take(50)
            ->get();

        return view('ai-providers.index', compact(
            'providers',
            'stats',
            'recentLogs',
            'statsByType',
            'statsPerProvider',
            'pendingAliases',
            'aliases',
        ));
    }

    /**
     * Simpan provider AI baru ke database.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'provider_type'       => 'required|in:groq,ollama,openai',
            'api_key_encrypted'   => 'nullable|string',
            'model'               => 'required|string',
            'endpoint_url'        => 'nullable|url',
            'priority'            => 'required|integer|min:1',
            'monthly_token_limit' => 'required|integer|min:0',
            'daily_token_limit'   => 'required|integer|min:0',
            'status'              => 'required|in:healthy,exhausted,error,disabled',
        ]);

        AiProvider::create($validated);

        return redirect()->route('ai-providers.index')
            ->with('success', 'Provider AI berhasil ditambahkan.');
    }

    /**
     * Perbarui data provider AI yang sudah ada.
     *
     * @param Request    $request
     * @param AiProvider $aiProvider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, AiProvider $aiProvider)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'provider_type'       => 'required|in:groq,ollama,openai',
            'api_key_encrypted'   => 'nullable|string',
            'model'               => 'required|string',
            'endpoint_url'        => 'nullable|url',
            'priority'            => 'required|integer|min:1',
            'monthly_token_limit' => 'required|integer|min:0',
            'daily_token_limit'   => 'required|integer|min:0',
            'status'              => 'required|in:healthy,exhausted,error,disabled',
        ]);

        $aiProvider->update($validated);

        return redirect()->route('ai-providers.index')
            ->with('success', 'Provider AI berhasil diperbarui.');
    }

    /**
     * Hapus provider AI dari database.
     *
     * @param AiProvider $aiProvider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(AiProvider $aiProvider)
    {
        $aiProvider->delete();

        return redirect()->route('ai-providers.index')
            ->with('success', 'Provider AI berhasil dihapus.');
    }

    /**
     * Test koneksi ke satu provider AI dengan benar-benar memanggil API.
     *
     * Mengirim prompt minimal ke endpoint provider dan memverifikasi
     * bahwa respons HTTP 200 diterima dan berisi konten yang valid.
     * Status provider diperbarui berdasarkan hasil aktual dari API.
     *
     * @param AiProvider $aiProvider
     * @return \Illuminate\Http\JsonResponse
     */
    public function test(AiProvider $aiProvider)
    {
        $startTime    = microtime(true);
        $responseTime = 0;

        try {
            $result       = $this->pingProvider($aiProvider);
            $responseTime = $result['response_time_ms'];

            if ($result['success']) {
                $aiProvider->update([
                    'last_health_check' => now(),
                    'status'            => 'healthy',
                ]);

                // Catat ke usage log sebagai health_check
                $this->logUsage($aiProvider, $responseTime, 'health_check', 'success');

                return response()->json([
                    'success' => true,
                    'message' => "Koneksi berhasil. Response time: {$responseTime}ms",
                ]);
            }

            // Tentukan status error berdasarkan HTTP code
            $newStatus = $result['http_code'] === 429 ? 'exhausted' : 'error';

            $aiProvider->update([
                'last_health_check' => now(),
                'status'            => $newStatus,
            ]);

            $this->logUsage($aiProvider, $responseTime, 'health_check', 'error', $result['error']);

            return response()->json([
                'success' => false,
                'message' => 'Gagal: ' . $result['error'],
            ]);

        } catch (\Exception $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            $aiProvider->update([
                'last_health_check' => now(),
                'status'            => 'error',
            ]);

            $this->logUsage($aiProvider, $responseTime, 'health_check', 'error', $e->getMessage());

            Log::error("AiProviderController::test() exception", [
                'provider' => $aiProvider->name,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Test koneksi ke semua provider AI sekaligus dengan memanggil API nyata.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testAll()
    {
        $providers = AiProvider::all();
        $results   = [];

        foreach ($providers as $provider) {
            try {
                $result       = $this->pingProvider($provider);
                $responseTime = $result['response_time_ms'];

                if ($result['success']) {
                    $provider->update([
                        'last_health_check' => now(),
                        'status'            => 'healthy',
                    ]);

                    $this->logUsage($provider, $responseTime, 'health_check', 'success');

                    $results[] = [
                        'name'          => $provider->name,
                        'success'       => true,
                        'response_time' => $responseTime,
                    ];
                } else {
                    $newStatus = $result['http_code'] === 429 ? 'exhausted' : 'error';

                    $provider->update([
                        'last_health_check' => now(),
                        'status'            => $newStatus,
                    ]);

                    $this->logUsage($provider, $responseTime, 'health_check', 'error', $result['error']);

                    $results[] = [
                        'name'    => $provider->name,
                        'success' => false,
                        'error'   => $result['error'],
                    ];
                }

            } catch (\Exception $e) {
                $provider->update([
                    'last_health_check' => now(),
                    'status'            => 'error',
                ]);

                Log::error("AiProviderController::testAll() exception", [
                    'provider' => $provider->name,
                    'error'    => $e->getMessage(),
                ]);

                $results[] = [
                    'name'    => $provider->name,
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Reset kuota harian (tokens_used_today & request_count_24h) semua provider.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resetQuota()
    {
        AiProvider::query()->update([
            'tokens_used_today'  => 0,
            'request_count_24h'  => 0,
        ]);

        return redirect()->route('ai-providers.index')
            ->with('success', 'Kuota harian semua provider telah direset.');
    }

    /**
     * Konfirmasi alias AI (ubah status menjadi confirmed).
     *
     * @param AiAlias $alias
     * @return \Illuminate\Http\RedirectResponse
     */
    public function confirmAlias(AiAlias $alias)
    {
        $alias->update([
            'status'       => 'confirmed',
            'confirmed_by' => Auth::id(),
        ]);

        return redirect()->route('ai-providers.index')
            ->with('success', 'Alias "' . $alias->alias_text . '" berhasil dikonfirmasi.');
    }

    /**
     * Tolak alias AI (ubah status menjadi rejected).
     *
     * @param AiAlias $alias
     * @return \Illuminate\Http\RedirectResponse
     */
    public function rejectAlias(AiAlias $alias)
    {
        $alias->update([
            'status' => 'rejected',
        ]);

        return redirect()->route('ai-providers.index')
            ->with('success', 'Alias "' . $alias->alias_text . '" berhasil ditolak.');
    }

    // =========================================================
    // Private helpers
    // =========================================================

    /**
     * Ping satu provider AI dengan mengirim prompt minimal ke API-nya.
     *
     * Mendukung provider_type: groq, openai (format OpenAI-compatible),
     * dan ollama (endpoint berbeda, tidak perlu API key).
     *
     * @param  AiProvider $provider
     * @return array{success: bool, response_time_ms: int, http_code: int|null, error: string|null}
     */
    private function pingProvider(AiProvider $provider): array
    {
        $startTime = microtime(true);

        // Dekripsi API key menggunakan accessor yang sudah ada di model
        $apiKey = $provider->api_key;

        // Tentukan endpoint berdasarkan provider_type
        $endpoint = $this->resolveEndpoint($provider);

        // Prompt minimal untuk health check — tidak butuh respons panjang
        $payload = $this->buildPingPayload($provider);

        $httpCode = null;

        try {
            $headers = ['Content-Type' => 'application/json'];

            // Ollama tidak pakai Authorization header
            if ($provider->provider_type !== 'ollama') {
                if (empty($apiKey)) {
                    return [
                        'success'         => false,
                        'response_time_ms'=> 0,
                        'http_code'       => null,
                        'error'           => 'API key kosong. Isi API key provider terlebih dahulu.',
                    ];
                }
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post($endpoint, $payload);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $httpCode     = $response->status();

            if ($response->successful()) {
                // Pastikan respons benar-benar mengandung konten model
                $content = $this->extractContent($provider, $response->json());

                if ($content !== null) {
                    return [
                        'success'          => true,
                        'response_time_ms' => $responseTime,
                        'http_code'        => $httpCode,
                        'error'            => null,
                    ];
                }

                return [
                    'success'          => false,
                    'response_time_ms' => $responseTime,
                    'http_code'        => $httpCode,
                    'error'            => 'Respons API tidak mengandung konten yang valid.',
                ];
            }

            // HTTP error — buat pesan yang informatif
            $errorBody = substr($response->body(), 0, 200);
            $errorMsg  = match ($httpCode) {
                401     => 'API key tidak valid atau tidak diizinkan (401 Unauthorized).',
                403     => 'Akses ditolak oleh provider (403 Forbidden).',
                429     => 'Kuota API habis (429 Too Many Requests).',
                500     => 'Server provider error (500 Internal Server Error).',
                default => "HTTP {$httpCode}: {$errorBody}",
            };

            return [
                'success'          => false,
                'response_time_ms' => $responseTime,
                'http_code'        => $httpCode,
                'error'            => $errorMsg,
            ];

        } catch (\Exception $e) {
            return [
                'success'          => false,
                'response_time_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'http_code'        => $httpCode,
                'error'            => $e->getMessage(),
            ];
        }
    }

    /**
     * Tentukan endpoint URL yang akan digunakan untuk ping.
     *
     * @param  AiProvider $provider
     * @return string
     */
    private function resolveEndpoint(AiProvider $provider): string
    {
        if (!empty($provider->endpoint_url)) {
            return $provider->endpoint_url;
        }

        return match ($provider->provider_type) {
            'groq'   => 'https://api.groq.com/openai/v1/chat/completions',
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'ollama' => 'http://localhost:11434/api/chat',
            default  => 'https://api.groq.com/openai/v1/chat/completions',
        };
    }

    /**
     * Bangun payload minimal untuk health check.
     * max_tokens=1 agar tidak membuang kuota untuk ping.
     *
     * @param  AiProvider $provider
     * @return array
     */
    private function buildPingPayload(AiProvider $provider): array
    {
        $model = $provider->model ?? 'llama-3.3-70b-versatile';

        // Ollama pakai format berbeda
        if ($provider->provider_type === 'ollama') {
            return [
                'model'    => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'ping'],
                ],
                'stream'   => false,
            ];
        }

        // Groq / OpenAI pakai format OpenAI-compatible
        return [
            'model'      => $model,
            'messages'   => [
                ['role' => 'user', 'content' => 'ping'],
            ],
            'max_tokens' => 1,
        ];
    }

    /**
     * Ekstrak konten dari respons API berdasarkan format provider.
     *
     * @param  AiProvider $provider
     * @param  array|null $json
     * @return string|null  Konten teks, atau null jika tidak valid
     */
    private function extractContent(AiProvider $provider, ?array $json): ?string
    {
        if (empty($json)) {
            return null;
        }

        // Format Ollama
        if ($provider->provider_type === 'ollama') {
            return $json['message']['content'] ?? null;
        }

        // Format OpenAI-compatible (Groq & OpenAI)
        return $json['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Simpan log penggunaan untuk health check ke tabel ai_usage_logs.
     *
     * @param  AiProvider  $provider
     * @param  int         $responseTimeMs
     * @param  string      $requestType
     * @param  string      $status           'success'|'error'
     * @param  string|null $errorMessage
     * @return void
     */
    private function logUsage(
        AiProvider $provider,
        int $responseTimeMs,
        string $requestType,
        string $status,
        ?string $errorMessage = null
    ): void {
        try {
            AiUsageLog::create([
                'provider_id'      => $provider->id,
                'tokens_used'      => 0,   // Health check tidak mengkonsumsi token signifikan
                'request_type'     => $requestType,
                'response_time_ms' => $responseTimeMs,
                'status'           => $status,
                'error_message'    => $errorMessage,
            ]);
        } catch (\Exception $e) {
            // Jangan biarkan kegagalan logging mengganggu respons utama
            Log::warning("AiProviderController: gagal menyimpan usage log", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
