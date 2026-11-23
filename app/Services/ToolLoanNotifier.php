<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\ToolLoan;
use App\Models\ToolToken;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi Telegram untuk transaksi peminjaman alat.
 *
 * Hanya peminjam yang punya telegram_id yang dikirimi pesan. Kegagalan kirim
 * tidak membatalkan transaksi; cukup dicatat di log.
 */
class ToolLoanNotifier
{
    public function __construct(
        private TelegramService $telegram,
        private ToolTokenService $token
    ) {
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
     * Beri tahu semua tool keeper bahwa ada pengajuan pengembalian.
     *
     * Setiap keeper mendapat tombol Terima dan tautan dashboard keeper yang
     * bisa dibuka tanpa login web (token).
     */
    public function kirimPermintaanKembali(ToolLoan $pinjaman): void
    {
        $keepers = Technician::query()
            ->active()
            ->toolKeeper()
            ->whereNotNull('telegram_id')
            ->get();

        if ($keepers->isEmpty()) {
            Log::info('ToolLoanNotifier: tidak ada keeper aktif untuk notifikasi pengembalian', [
                'pinjaman_id' => $pinjaman->id,
            ]);

            return;
        }

        foreach ($keepers as $keeper) {
            $tautan = $this->token->buatToken($keeper, ToolToken::PURPOSE_KEEPER_KEMBALI, $pinjaman->tool_id);

            $pesan = "Permintaan pengembalian alat:\n"
                . '<b>' . e($pinjaman->tool_name) . '</b> (' . e($pinjaman->tool_code) . ")\n"
                . 'Peminjam: ' . e($pinjaman->peminjamLabel()) . "\n"
                . 'Diajukan: ' . $pinjaman->return_requested_at?->format('d/m/Y H:i') . "\n\n"
                . 'Periksa fisik alat, lalu tekan Terima bila sudah sesuai.';

            $this->kirimKeyboard((string) $keeper->telegram_id, $pesan, [
                ['text' => 'Terima', 'callback_data' => 'alat:terima:' . $pinjaman->id],
                ['text' => 'Buka Dashboard', 'url' => $tautan['url']],
            ], $pinjaman->id);
        }
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

    /**
     * Kirim pesan yang memiliki tombol inline (callback dan/atau URL).
     *
     * @param array<int,array{text:string,callback_data?:string,url?:string}> $tombol
     */
    private function kirimKeyboard(string $chatId, string $pesan, array $tombol, int $pinjamanId): void
    {
        try {
            $baris = [];

            foreach ($tombol as $satu) {
                $baris[] = [$satu];
            }

            $this->telegram->sendMessage($chatId, $pesan, [
                'reply_markup' => ['inline_keyboard' => $baris],
            ]);
        } catch (\Throwable $galat) {
            Log::warning('ToolLoanNotifier: gagal mengirim Telegram berkunci', [
                'pinjaman_id' => $pinjamanId,
                'error'       => $galat->getMessage(),
            ]);
        }
    }
}
