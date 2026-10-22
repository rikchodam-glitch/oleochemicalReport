<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $token;
    protected string $apiUrl;

    public function __construct()
    {
        $this->token  = config('services.telegram.bot_token', '');
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->token;
    }

    /**
     * Periksa apakah token bot sudah dikonfigurasi.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    /**
     * Kirim pesan teks ke chat ID tertentu.
     *
     * @param  int|string  $chatId ID chat Telegram tujuan
     * @param  string      $text   Isi pesan (mendukung HTML)
     * @param  array       $extra  Parameter tambahan untuk Telegram API
     * @return bool                True jika berhasil terkirim
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): bool
    {
        if (!$this->isConfigured()) {
            Log::info("[Telegram Mock] Would send to {$chatId}: {$text}");
            return false;
        }

        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}/sendMessage", array_merge([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ], $extra));

            $success = $response->successful();

            if (!$success) {
                Log::warning("Telegram sendMessage failed to {$chatId}: " . $response->body());
            }

            return $success;
        } catch (\Exception $e) {
            Log::error("Telegram sendMessage exception to {$chatId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Kirim pesan ke banyak teknisi sekaligus (broadcast).
     * Hanya teknisi aktif dengan telegram_id yang akan dikirimi.
     *
     * @param  array        $technicianIds Daftar ID teknisi yang dituju
     * @param  string       $message       Isi pesan yang akan dikirim
     * @param  string|null  $assetInfo     Info asset yang ditambahkan di awal pesan (opsional)
     * @return array{sent: int, failed: int, total: int, details: array}
     */
    public function broadcastToTechnicians(array $technicianIds, string $message, ?string $assetInfo = null): array
    {
        $technicians = \App\Models\Technician::whereIn('id', $technicianIds)
            ->where('status', 'active')
            ->whereNotNull('telegram_id')
            ->get();

        $results = [
            'sent'    => 0,
            'failed'  => 0,
            'total'   => $technicians->count(),
            'details' => [],
        ];

        foreach ($technicians as $technician) {
            $fullMessage = $message;
            if ($assetInfo) {
                $fullMessage = $assetInfo . "\n\n" . $message;
            }

            $success = $this->sendMessage($technician->telegram_id, $fullMessage);

            if ($success) {
                $results['sent']++;
                $results['details'][] = [
                    'technician_id' => $technician->id,
                    'name'          => $technician->name,
                    'status'        => 'sent',
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'technician_id' => $technician->id,
                    'name'          => $technician->name,
                    'status'        => 'failed',
                ];
            }
        }

        return $results;
    }

    /**
     * Format informasi asset menjadi string HTML untuk dikirim via Telegram.
     * Dipakai sebagai header pesan notifikasi penugasan ke teknisi.
     *
     * @param  \App\Models\Asset  $asset
     * @return string
     */
    public function formatAssetInfo(\App\Models\Asset $asset): string
    {
        $lines   = [];
        $lines[] = "<b>ASSET ASSIGNMENT</b>";
        $lines[] = "";
        $lines[] = "<b>Kode Alat:</b> " . ($asset->tech_ident_no ?? '-');
        if ($asset->equipment_no) {
            $lines[] = "<b>Equipment No:</b> " . $asset->equipment_no;
        }
        $lines[] = "<b>Deskripsi:</b> " . ($asset->description ?? '-');
        $lines[] = "<b>Lokasi:</b> " . ($asset->area?->code ?? '-') . " / " . ($asset->subArea?->code ?? '-');
        if ($asset->functional_loc) {
            $lines[] = "<b>Functional Loc:</b> " . $asset->functional_loc;
        }

        return implode("\n", $lines);
    }

    /**
     * Daftar command yang muncul pada menu bot Telegram.
     *
     * Hanya command yang benar-benar ditangani bot.
     */
    public const MENU_COMMANDS = [
        ['command' => 'start', 'description' => 'Mulai bot / daftar teknisi'],
        ['command' => 'laporan', 'description' => 'Buat laporan lewat form web'],
        ['command' => 'info', 'description' => 'Cari informasi perangkat'],
        ['command' => 'reset', 'description' => 'Batalkan sesi laporan'],
    ];

    /**
     * Daftarkan command ke menu bot (tombol di samping emoji).
     *
     * @return bool
     */
    public function setBotCommands(): bool
    {
        if (! $this->isConfigured()) {
            Log::info('[Telegram] setMyCommands dilewati: token belum diisi.');

            return false;
        }

        try {
            $response = Http::timeout(10)->asJson()->post("{$this->apiUrl}/setMyCommands", [
                'commands' => self::MENU_COMMANDS,
                'scope'    => ['type' => 'default'],
            ]);

            if (! $response->successful()) {
                Log::warning('[Telegram] setMyCommands gagal: ' . $response->body());

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Telegram] setMyCommands error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Pastikan tombol menu default Telegram aktif.
     *
     * @return bool
     */
    public function setDefaultMenuButton(): bool
    {
        if (! $this->isConfigured()) {
            Log::info('[Telegram] setChatMenuButton dilewati: token belum diisi.');

            return false;
        }

        try {
            $response = Http::timeout(10)->asJson()->post("{$this->apiUrl}/setChatMenuButton", [
                'menu_button' => ['type' => 'default'],
            ]);

            if (! $response->successful()) {
                Log::warning('[Telegram] setChatMenuButton gagal: ' . $response->body());

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Telegram] setChatMenuButton error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Sinkronkan command dan menu button bot.
     *
     * @return array{commands: bool, menu: bool}
     */
    public function sinkronkanMenuBot(): array
    {
        return [
            'commands' => $this->setBotCommands(),
            'menu'     => $this->setDefaultMenuButton(),
        ];
    }
}
