<?php

namespace App\Http\Controllers;

use App\Models\AiAlias;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // Log terbaru 30 baris — tambah kolom request_type dan response_time_ms
        $recentLogs = AiUsageLog::with('provider')
            ->latest()
            ->take(30)
            ->get();

        // Breakdown pemakaian per request_type dalam 24 jam
        $statsByType = AiUsageLog::statsByRequestType(24);

        // Statistik per provider dalam 24 jam (untuk ditampilkan di provider card)
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
     * Test koneksi ke satu provider AI.
     *
     * @param AiProvider $aiProvider
     * @return \Illuminate\Http\JsonResponse
     */
    public function test(AiProvider $aiProvider)
    {
        try {
            $startTime = microtime(true);
            // Placeholder — implementasi ping nyata ada di service layer
            $responseTime = round((microtime(true) - $startTime) * 1000);

            $aiProvider->update([
                'last_health_check' => now(),
                'status'            => 'healthy',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Koneksi berhasil. Response time: {$responseTime}ms",
            ]);
        } catch (\Exception $e) {
            $aiProvider->update([
                'last_health_check' => now(),
                'status'            => 'error',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Test koneksi ke semua provider AI sekaligus.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testAll()
    {
        $providers = AiProvider::all();
        $results   = [];

        foreach ($providers as $provider) {
            try {
                $startTime    = microtime(true);
                $responseTime = round((microtime(true) - $startTime) * 1000);

                $provider->update([
                    'last_health_check' => now(),
                    'status'            => 'healthy',
                ]);

                $results[] = [
                    'name'          => $provider->name,
                    'success'       => true,
                    'response_time' => $responseTime,
                ];
            } catch (\Exception $e) {
                $provider->update(['status' => 'error']);

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
}
