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
    public function simpan(string $chatId, Technician $teknisi, array $hasil): void
    {
        $ttl = max(1, (int) config('voice.pending_ttl_menit', 10));

        Cache::put(self::PREFIX . $chatId, [
            'technician_id' => $teknisi->id,
            'text'          => (string) ($hasil['text'] ?? ''),
            'confidence'    => (string) ($hasil['confidence'] ?? 'sedang'),
            'duration'      => (int) ($hasil['duration'] ?? 0),
            'model'         => (string) ($hasil['model'] ?? ''),
            'created_at'    => now()->toIso8601String(),
        ], now()->addMinutes($ttl));
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
