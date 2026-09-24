<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiModelDiscoveryService
 *
 * Mengambil daftar model yang BENAR-BENAR tersedia pada akun provider AI
 * (Groq dan penyedia bergaya OpenAI) melalui endpoint `GET {base}/models`,
 * lalu memilih model chat yang paling layak dipakai.
 *
 * Tujuan: kolom "model" pada panel AI tidak perlu diketik manual lagi — nama
 * model yang salah (mis. "groq/compound") membuat semua panggilan gagal dengan
 * HTTP 404 "model does not exist" dan provider ikut ditandai error.
 */
class AiModelDiscoveryService
{
    /**
     * Model bawaan bila tidak ada model yang bisa dideteksi dari API.
     *
     * @var string
     */
    public const DEFAULT_MODEL = 'openai/gpt-oss-120b';

    /**
     * Urutan prioritas model chat yang dipilih otomatis bila tersedia di akun.
     * Diambil dari yang paling besar/kapabel lebih dulu.
     *
     * @var array<int, string>
     */
    public const PREFERRED_CHAT_MODELS = [
        'openai/gpt-oss-120b',
        'openai/gpt-oss-20b',
        'qwen/qwen3.8-27b',
        'meta-llama/llama-4-scout-17b-16e-instruct',
        'meta-llama/llama-4-maverick-17b-128e-instruct',
        'llama-3.3-70b-versatile',
        'llama-3.1-8b-instant',
        'allam-2-7b',
    ];

    /**
     * Kata kunci nama model yang BUKAN untuk chat (audio, penjaga prompt,
     * text-to-speech) sehingga tidak dipilih otomatis.
     *
     * @var array<int, string>
     */
    protected const NON_CHAT_KEYWORDS = [
        'whisper', 'tts', 'orpheus', 'prompt-guard', 'embed', 'moderation',
        'safeguard',
    ];

    /**
     * Ambil daftar model dari provider.
     *
     * @param  string|null  $endpointUrl  URL penuh (boleh berisi /chat/completions) atau null
     * @param  string       $apiKey       API key yang sudah didekripsi
     * @return array{ok: bool, status: int, models: array<int, string>, error: string|null}
     */
    public function fetch(?string $endpointUrl, string $apiKey): array
    {
        $base = $this->resolveBaseUrl($endpointUrl);

        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0, 'models' => [], 'error' => 'API key kosong.'];
        }

        try {
            $respons = Http::withToken($apiKey)->timeout(20)->get($base . '/models');
        } catch (\Throwable $e) {
            Log::warning('AiModelDiscoveryService: gagal menghubungi provider', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'models' => [], 'error' => $e->getMessage()];
        }

        if (! $respons->successful()) {
            return [
                'ok'     => false,
                'status' => $respons->status(),
                'models' => [],
                'error'  => mb_substr((string) $respons->body(), 0, 300),
            ];
        }

        $data   = $respons->json();
        $models = [];

        foreach ((array) ($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? ''));

            if ($id !== '') {
                $models[] = $id;
            }
        }

        sort($models);

        return ['ok' => true, 'status' => $respons->status(), 'models' => $models, 'error' => null];
    }

    /**
     * Pilih model chat terbaik dari daftar model yang tersedia.
     *
     * @param  array<int, string>  $models
     * @return string|null
     */
    public function pickChatModel(array $models): ?string
    {
        foreach (self::PREFERRED_CHAT_MODELS as $kandidat) {
            if (in_array($kandidat, $models, true)) {
                return $kandidat;
            }
        }

        foreach ($models as $model) {
            if ($this->isChatModel($model)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Apakah nama model layak dipakai untuk chat (bukan audio/penjaga prompt).
     *
     * @param  string  $model
     * @return bool
     */
    public function isChatModel(string $model): bool
    {
        $nama = strtolower($model);

        foreach (self::NON_CHAT_KEYWORDS as $kata) {
            if (str_contains($nama, $kata)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Endpoint chat/completions efektif dari sebuah provider.
     *
     * @param  \App\Models\AiProvider  $provider
     * @return string
     */
    public function providerEndpoint($provider): string
    {
        if (! empty($provider->endpoint_url)) {
            return (string) $provider->endpoint_url;
        }

        return match ($provider->provider_type) {
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'ollama' => 'http://localhost:11434/api/chat',
            default  => 'https://api.groq.com/openai/v1/chat/completions',
        };
    }

    /**
     * Daftar model yang tersedia di akun provider (GET /models).
     *
     * @param  \App\Models\AiProvider  $provider
     * @return array{ok: bool, status: int, models: array<int, string>, error: string|null}
     */
    public function availableModels($provider): array
    {
        return $this->fetch($this->providerEndpoint($provider), (string) $provider->api_key);
    }

    /**
     * Deteksi model chat terbaik dari akun provider lalu simpan ke kolom model.
     *
     * Dipakai untuk mengoreksi otomatis nama model yang sudah tidak valid
     * (mis. model dihentikan provider) tanpa campur tangan manual di panel.
     *
     * @param  \App\Models\AiProvider  $provider
     * @return string|null  Nama model yang disimpan, atau null bila deteksi gagal
     */
    public function detectAndSave($provider): ?string
    {
        $hasil = $this->availableModels($provider);

        if (! $hasil['ok']) {
            Log::warning('AiModelDiscoveryService: deteksi model gagal', [
                'provider' => $provider->name ?? null,
                'error'    => $hasil['error'],
            ]);

            return null;
        }

        $model = $this->pickChatModel($hasil['models']);

        if ($model === null) {
            return null;
        }

        $provider->update(['model' => $model]);

        return $model;
    }

    /**
     * Apakah pesan error dari provider menunjuk ke masalah nama model.
     *
     * @param  string  $pesan
     * @return bool
     */
    public function isModelErrorMessage(string $pesan): bool
    {
        $lower = strtolower($pesan);
        $kata  = ['model_decommissioned', 'does not exist', 'invalid model', 'unknown model'];

        foreach ($kata as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ubah URL endpoint menjadi base URL untuk memanggil /models.
     *
     * @param  string|null  $endpointUrl
     * @return string
     */
    public function resolveBaseUrl(?string $endpointUrl): string
    {
        $url = trim((string) ($endpointUrl ?: 'https://api.groq.com/openai/v1/chat/completions'));

        $url = preg_replace('#/chat/completions/?$#i', '', $url) ?? $url;
        $url = preg_replace('#/completions/?$#i', '', $url) ?? $url;

        return rtrim($url, '/');
    }
}
