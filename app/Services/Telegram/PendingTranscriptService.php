<?php

namespace App\Services\Telegram;

use App\Models\Technician;
use Illuminate\Support\Facades\Cache;

/**
 * Menyimpan transkrip suara yang menunggu persetujuan Gunakan/Ulangi/Batal.
 *
 * Satu chat hanya punya satu transkrip menunggu. Transkrip baru menggantikan
 * transkrip lama; pesan teks biasa/photo juga menghapus transkrip menunggu.
 */
class PendingTranscriptService
{
    private const PREFIX = 'voice_pending:';

    /**
     * Simpan transkrip menunggu persetujuan.
     *
     * @param array<string,mixed> $hasil Hasil SpeechToTextService
     */
    public function simpan(string $chatId, Technician $teknisi, array $hasil, bool $dukungCallback = true): void
    {
        $ttl = max(1, (int) config('voice.pending_ttl_menit', 10));

        Cache::put(self::PREFIX . $chatId, [
            'technician_id'  => $teknisi->id,
            'text'           => (string) ($hasil['text'] ?? ''),
            'confidence'     => (string) ($hasil['confidence'] ?? 'sedang'),
            'duration'       => (int) ($hasil['duration'] ?? 0),
            'model'          => (string) ($hasil['model'] ?? ''),
            'mode'           => 'confirm',
            'dukung_callback' => $dukungCallback,
            'created_at'     => now()->toIso8601String(),
        ], now()->addMinutes($ttl));
    }

    /**
     * Ubah mode transkrip menunggu: confirm atau edit.
     */
    public function setMode(string $chatId, string $mode): void
    {
        $data = $this->ambil($chatId);

        if ($data === null) {
            return;
        }

        $data['mode'] = $mode;

        Cache::put(
            self::PREFIX . $chatId,
            $data,
            now()->addMinutes(max(1, (int) config('voice.pending_ttl_menit', 10)))
        );
    }

    /**
     * Ganti teks transkrip dengan koreksi pengguna.
     *
     * @return array<string,mixed>|null
     */
    public function gantiTeks(string $chatId, string $text): ?array
    {
        $data = $this->ambil($chatId);

        if ($data === null) {
            return null;
        }

        $data['text']       = trim($text);
        $data['confidence'] = 'koreksi';
        $data['mode']       = 'confirm';

        Cache::put(
            self::PREFIX . $chatId,
            $data,
            now()->addMinutes(max(1, (int) config('voice.pending_ttl_menit', 10)))
        );

        return $data;
    }

    /**
     * Ambil transkrip yang menunggu.
     *
     * @return array<string,mixed>|null
     */
    public function ambil(string $chatId): ?array
    {
        $data = Cache::get(self::PREFIX . $chatId);

        return is_array($data) ? $data : null;
    }

    /**
     * Apakah chat punya transkrip menunggu.
     */
    public function ada(string $chatId): bool
    {
        return Cache::has(self::PREFIX . $chatId);
    }

    /**
     * Hapus transkrip menunggu.
     */
    public function hapus(string $chatId): void
    {
        Cache::forget(self::PREFIX . $chatId);
    }
}
