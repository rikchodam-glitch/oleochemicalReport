<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\Asset;
use App\Models\Tool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transkripsi pesan suara memakai Groq Whisper.
 *
 * Kunci API diambil dari tabel ai_providers (provider_type = groq, status
 * healthy) sehingga tidak ada kredensial baru di .env. Bila satu provider
 * gagal karena kuota/server, provider berikutnya dicoba.
 */
class SpeechToTextService
{
    /**
     * Transkripsi satu file audio lokal.
     *
     * @param  string   $audioPath Path relatif di disk voice.disk
     * @param  int|null $durasi    Durasi voice note menurut Telegram (detik)
     * @return array{ok:bool,text?:string,language?:string,duration?:int,confidence?:string,latency_ms?:int,model?:string,error?:string}
     */
    public function transcribe(string $audioPath, ?int $durasi = null): array
    {
        if (! config('voice.enabled', true)) {
            return ['ok' => false, 'error' => 'Transkripsi suara sedang dinonaktifkan.'];
        }

        $disk = (string) config('voice.disk', 'local');
        $absolute = Storage::disk($disk)->path($audioPath);

        if (! is_file($absolute) || filesize($absolute) === 0) {
            return ['ok' => false, 'error' => 'Berkas audio tidak ditemukan.'];
        }

        $providers = AiProvider::query()
            ->where('status', 'healthy')
            ->where('provider_type', (string) config('voice.provider_type', 'groq'))
            ->orderBy('priority')
            ->get();

        if ($providers->isEmpty()) {
            return ['ok' => false, 'error' => 'Provider transkripsi tidak tersedia.'];
        }

        $model     = (string) config('voice.model', 'whisper-large-v3-turbo');
        $endpoint  = (string) config('voice.endpoint', 'https://api.groq.com/openai/v1/audio/transcriptions');
        $prompt    = $this->prompt();
        $mime      = mime_content_type($absolute) ?: 'application/octet-stream';
        $galatAkhir = 'Transkripsi gagal.';

        foreach ($providers as $provider) {
            $apiKey = (string) $provider->api_key;

            if ($apiKey === '') {
                continue;
            }

            $mulai = microtime(true);

            try {
                $handle = fopen($absolute, 'rb');

                try {
                    $respons = Http::withToken($apiKey)
                        ->timeout((int) config('voice.timeout_detik', 60))
                        ->attach('file', $handle, basename($absolute), ['Content-Type' => $mime])
                        ->post($endpoint, [
                            'model'           => $model,
                            'language'        => (string) config('voice.language', 'id'),
                            'prompt'          => $prompt,
                            'response_format' => 'verbose_json',
                            'temperature'     => 0,
                        ]);
                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            } catch (\Throwable $galat) {
                $galatAkhir = 'Transkripsi gagal: ' . $galat->getMessage();
                Log::warning('SpeechToText: exception', [
                    'provider' => $provider->id,
                    'error'    => $galat->getMessage(),
                ]);

                continue;
            }

            $latency = (int) round((microtime(true) - $mulai) * 1000);

            if (! $respons->successful()) {
                $bodyProvider = $respons->json();
                $pesanProvider = (string) ($bodyProvider['error']['message'] ?? '');

                $galatAkhir = str_contains(strtolower($pesanProvider), 'file must be one of the following types')
                    ? 'Format voice note tidak didukung. Coba kirim ulang.'
                    : 'Transkripsi gagal (HTTP ' . $respons->status() . ').';

                Log::warning('SpeechToText: provider menolak', [
                    'provider' => $provider->id,
                    'status'   => $respons->status(),
                    'body'     => mb_substr($respons->body(), 0, 300),
                ]);

                continue;
            }

            $data = $respons->json();
            $teks = trim((string) ($data['text'] ?? ''));

            if ($teks === '') {
                $galatAkhir = 'Tidak ada suara yang dapat ditranskripsi.';
                continue;
            }

            return [
                'ok'         => true,
                'text'       => $teks,
                'language'   => (string) ($data['language'] ?? config('voice.language', 'id')),
                'duration'   => (int) round((float) ($data['duration'] ?? $durasi ?? 0)),
                'confidence' => $this->confidence($data),
                'latency_ms' => $latency,
                'model'      => $model,
            ];
        }

        return ['ok' => false, 'error' => $galatAkhir];
    }

    /**
     * Prompt Whisper + istilah alat/aset agar kode teknis lebih akurat.
     */
    private function prompt(): string
    {
        $bagian = [(string) config('voice.prompt', '')];

        $batas = max(0, (int) config('voice.glossary_limit', 30));

        if ((bool) config('voice.glossary_enabled', false) && $batas > 0) {
            $alat = Tool::query()
                ->orderBy('name')
                ->limit($batas)
                ->get(['name', 'code'])
                ->map(fn (Tool $tool): string => trim($tool->name . ' ' . $tool->code))
                ->filter()
                ->implode(', ');

            if ($alat !== '') {
                $bagian[] = 'Nama alat dan kode: ' . $alat . '.';
            }

            $aset = Asset::query()
                ->whereNotNull('description')
                ->orderByDesc('id')
                ->limit(10)
                ->pluck('description')
                ->filter()
                ->implode(', ');

            if ($aset !== '') {
                $bagian[] = 'Nama equipment: ' . $aset . '.';
            }
        }

        return mb_substr(implode(' ', array_filter($bagian)), 0, 800);
    }

    /**
     * Nilai keyakinan transkrip dari segmen Whisper.
     *
     * @param array<string,mixed> $data Respons verbose_json
     */
    private function confidence(array $data): string
    {
        $segments = (array) ($data['segments'] ?? []);

        if ($segments === []) {
            return 'sedang';
        }

        $noSpeech = 0.0;
        $logprob  = 0.0;

        foreach ($segments as $segmen) {
            $noSpeech = max($noSpeech, (float) ($segmen['no_speech_prob'] ?? 0));
            $logprob  = min($logprob, (float) ($segmen['avg_logprob'] ?? 0));
        }

        if ($noSpeech >= 0.6 || $logprob <= -1.0) {
            return 'rendah';
        }

        if ($noSpeech >= 0.3 || $logprob <= -0.5) {
            return 'sedang';
        }

        return 'tinggi';
    }
}
