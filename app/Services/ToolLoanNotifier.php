<?php

namespace App\Services;

use App\Models\ToolLoan;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi Telegram untuk transaksi peminjaman alat.
 *
 * Hanya peminjam yang punya telegram_id yang dikirimi pesan. Kegagalan kirim
 * tidak membatalkan transaksi; cukup dicatat di log.
 */
class ToolLoanNotifier
{
    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * Kabari peminjam teknisi bahwa alat sudah tercatat dipinjam.
     */
    public function kirimDipinjam(ToolLoan $pinjaman): void
    {
        $teknisi = $pinjaman->technician;

        if ($teknisi === null || blank($teknisi->telegram_id)) {
            return;
        }

        $pesan = 'Alat <b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ')'
            . ' tercatat dipinjam atas nama <b>' . e($pinjaman->peminjamLabel()) . '</b>.'
            . "\nKode pinjaman: " . e($pinjaman->kode)
            . "\nKetik /selesai bila alat sudah dikembalikan.";

        $this->kirim((string) $teknisi->telegram_id, $pesan, $pinjaman->id);
    }

    /**
     * Kabari peminjam teknisi bahwa alat sudah dikembalikan.
     */
    public function kirimDikembalikan(ToolLoan $pinjaman): void
    {
        $teknisi = $pinjaman->technician;

        if ($teknisi === null || blank($teknisi->telegram_id)) {
            return;
        }

        $pesan = 'Alat <b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ')'
            . ' sudah dikembalikan. Terima kasih.'
            . "\nKode pinjaman: " . e($pinjaman->kode);

        $this->kirim((string) $teknisi->telegram_id, $pesan, $pinjaman->id);
    }

    /**
     * Kirim pesan dengan penanganan galat yang aman.
     */
    private function kirim(string $chatId, string $pesan, int $pinjamanId): void
    {
        try {
            $this->telegram->sendMessage($chatId, $pesan);
        } catch (\Throwable $galat) {
            Log::warning('ToolLoanNotifier: gagal mengirim Telegram', [
                'pinjaman_id' => $pinjamanId,
                'error'       => $galat->getMessage(),
            ]);
        }
    }
}
