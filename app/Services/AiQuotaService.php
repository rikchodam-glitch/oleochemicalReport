<?php

namespace App\Services;

use App\Models\AiProvider;
use Illuminate\Support\Facades\Log;

/**
 * AiQuotaService
 *
 * Mengelola kuota provider AI berdasarkan data NYATA dari API provider.
 *
 * Latar belakang: Groq tidak menyediakan endpoint sisa kuota (GET /usage,
 * /limits, /rate_limits -> 404). Yang tersedia hanya header pada setiap respons
 * chat:
 *   x-ratelimit-limit-tokens / x-ratelimit-remaining-tokens / x-ratelimit-reset-tokens
 *   x-ratelimit-limit-requests / x-ratelimit-remaining-requests / x-ratelimit-reset-requests
 * dan HTTP 429 (disertai retry-after) saat batas terlampaui.
 *
 * Karena itu kuota TIDAK lagi ditebak dengan angka tetap di aplikasi: nilai
 * header disimpan apa adanya, dan saat kena 429 provider hanya dilewati selama
 * masa tunggu (cooldown) yang disebutkan API - bukan dimatikan sampai ada yang
 * mengklik tombol reset.
 */
class AiQuotaService
{
    /**
     * Lama tunggu bawaan bila API tidak menyebutkan waktu reset (detik).
     */
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    /**
     * Simpan batas/sisa kuota dari header respons API yang berhasil.
     *
     * Sekaligus menghapus masa tunggu dan memulihkan status provider karena
     * panggilan ini membuktikan provider bisa dipakai lagi.
     *
     * @param  AiProvider  $provider
     * @param  array<string, mixed>  $headers
     * @return void
     */
    public function recordFromHeaders(AiProvider $provider, array $headers): void
    {
        $data = $this->headerValues($headers);

        $data['quota_checked_at'] = now();
        $data['cooldown_until']   = null;

        // Status exhausted/error dipulihkan: panggilan sukses membuktikan
        // provider ini berfungsi. Status 'disabled' tidak diubah karena itu
        // keputusan manual admin.
        if (in_array($provider->status, ['exhausted', 'error'], true)) {
            $data['status'] = 'healthy';
        }

        $provider->update($data);
    }

    /**
     * Tandai provider sedang dalam masa tunggu setelah HTTP 429.
     *
     * @param  AiProvider  $provider
     * @param  array<string, mixed>  $headers
     * @return int  Lama tunggu (detik) yang diterapkan
     */
    public function applyRateLimit(AiProvider $provider, array $headers): int
    {
        $detik = $this->parseDurationSeconds($this->header($headers, 'retry-after'))
            ?? $this->parseDurationSeconds($this->header($headers, 'x-ratelimit-reset-tokens'))
            ?? $this->parseDurationSeconds($this->header($headers, 'x-ratelimit-reset-requests'))
            ?? self::DEFAULT_COOLDOWN_SECONDS;

        $data = $this->headerValues($headers);

        $data['status']           = 'exhausted';
        $data['cooldown_until']   = now()->addSeconds($detik);
        $data['quota_checked_at'] = now();

        $provider->update($data);

        Log::warning("AI Service: Provider [{$provider->name}] kena batas API, tunggu {$detik} detik");

        return $detik;
    }

    /**
     * Apakah provider masih dalam masa tunggu (baru terkena HTTP 429).
     *
     * @param  AiProvider  $provider
     * @return bool
     */
    public function isCoolingDown(AiProvider $provider): bool
    {
        return $provider->cooldown_until !== null && $provider->cooldown_until->isFuture();
    }

    /**
     * Sisa masa tunggu dalam detik (0 bila tidak sedang menunggu).
     *
     * @param  AiProvider  $provider
     * @return int
     */
    public function cooldownRemainingSeconds(AiProvider $provider): int
    {
        if (! $this->isCoolingDown($provider)) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($provider->cooldown_until, false));
    }

    /**
     * Reset penghitung harian/bulanan saat tanggal atau bulan berganti.
     *
     * Dipanggil otomatis saat provider dipilih, sehingga reset TIDAK lagi
     * bergantung pada penjadwal atau tombol manual (dulu penghitung harian bisa
     * terlampaui permanen sehingga AI berhenti bekerja tanpa pesan).
     *
     * @param  AiProvider  $provider
     * @return bool  true bila reset dijalankan
     */
    public function resetCounterIfNewPeriod(AiProvider $provider): bool
    {
        $hariIni  = now()->toDateString();
        $terakhir = $provider->quota_day?->toDateString();

        if ($terakhir === $hariIni) {
            return false;
        }

        $data = [
            'tokens_used_today' => 0,
            'request_count_24h' => 0,
            'quota_day'         => $hariIni,
        ];

        // Penghitung bulanan direset hanya bila tanggal reset terakhir memang
        // berada di bulan yang berbeda. Bila quota_day masih kosong (provider
        // lama sebelum kolom ini ada), penghitung bulanan dibiarkan agar
        // estimasi pemakaian bulan berjalan tidak hilang.
        if ($provider->quota_day !== null && $provider->quota_day->format('Y-m') !== now()->format('Y-m')) {
            $data['tokens_used_month'] = 0;
        }

        // Status exhausted akibat kuota dipulihkan pada periode baru.
        if ($provider->status === 'exhausted') {
            $data['status'] = 'healthy';
        }

        $provider->update($data);

        return true;
    }

    /**
     * Ubah teks durasi dari header API menjadi detik.
     *
     * Format yang ditangani contohnya: "547ms", "1.5s", "45s", "1m26.4s",
     * "2h30m", "1h", dan angka polos yang berarti detik.
     *
     * @param  mixed  $nilai
     * @return int|null  Detik (minimal 1), atau null bila tidak bisa dibaca
     */
    public function parseDurationSeconds(mixed $nilai): ?int
    {
        $teks = trim((string) $nilai);

        if ($teks === '') {
            return null;
        }

        // Angka polos dianggap detik.
        if (is_numeric($teks)) {
            return max(1, (int) ceil((float) $teks));
        }

        $cocok = preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|s|m|h|d)/i', $teks, $cocokan, PREG_SET_ORDER);

        if (empty($cocok)) {
            return null;
        }

        $total = 0.0;

        foreach ($cocokan as $bagian) {
            $angka = (float) $bagian[1];

            $total += match (strtolower($bagian[2])) {
                'ms'    => $angka / 1000,
                's'     => $angka,
                'm'     => $angka * 60,
                'h'     => $angka * 3600,
                'd'     => $angka * 86400,
                default => 0,
            };
        }

        return max(1, (int) ceil($total));
    }

    /**
     * Kumpulkan nilai batas/sisa kuota dari header (hanya yang dikirim API).
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, int>
     */
    private function headerValues(array $headers): array
    {
        $peta = [
            'x-ratelimit-limit-tokens'       => 'quota_limit_tokens_per_minute',
            'x-ratelimit-remaining-tokens'   => 'quota_remaining_tokens_per_minute',
            'x-ratelimit-limit-requests'     => 'quota_limit_requests_per_minute',
            'x-ratelimit-remaining-requests' => 'quota_remaining_requests_per_minute',
        ];

        $data = [];

        foreach ($peta as $header => $kolom) {
            $nilai = $this->header($headers, $header);

            if ($nilai !== null && is_numeric(trim($nilai))) {
                $data[$kolom] = (int) trim($nilai);
            }
        }

        return $data;
    }

    /**
     * Ambil satu nilai header tanpa peduli huruf besar/kecil.
     *
     * @param  array<string, mixed>  $headers
     * @param  string  $nama
     * @return string|null
     */
    private function header(array $headers, string $nama): ?string
    {
        foreach ($headers as $kunci => $nilai) {
            if (strtolower((string) $kunci) !== strtolower($nama)) {
                continue;
            }

            if (is_array($nilai)) {
                $nilai = reset($nilai);
            }

            return $nilai === null ? null : (string) $nilai;
        }

        return null;
    }
}
