<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\TelegramMessageHandlerTrait;
use App\Console\Commands\Traits\TelegramSenderTrait;
use App\Models\Technician;
use App\Services\AiService;
use App\Services\Telegram\PhotoStorageService;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PollTelegramUpdates
 *
 * Command Artisan untuk long polling Telegram Bot API secara terus-menerus.
 * Berjalan sebagai proses background dan memproses update masuk satu per satu.
 *
 * Penggunaan:
 *   php artisan telegram:poll             # mulai polling
 *   php artisan telegram:poll --action=status  # cek status
 *   php artisan telegram:poll --action=stop    # hentikan dan reset offset
 *
 * Tanggung jawab class ini (orkestrator):
 *   - handle()            : entry point command, routing ke action
 *   - startPolling()      : loop utama long polling
 *   - processCallbackQuery() : routing callback inline keyboard
 *   - State management    : getLastOffset, saveLastOffset, updateLock
 *   - Status & kontrol    : showStatus, stopPolling
 *
 * Logika detail tersimpan di trait:
 *   - TelegramSenderTrait        : kirim, edit, dan jawab pesan/callback ke Telegram API
 *   - TelegramMessageHandlerTrait: routing pesan masuk, handler wizard, start, NIK, foto
 */
class PollTelegramUpdates extends Command
{
    use TelegramSenderTrait;
    use TelegramMessageHandlerTrait;

    protected $signature   = 'telegram:poll {--action= : start|stop|status}';
    protected $description = 'Poll Telegram updates continuously (long polling)';

    private AiService $aiService;
    private PhotoStorageService $photoStorage;
    private ReportWizardService $reportWizard;

    private string $offsetFile;
    private string $lockFile;
    private string $stopFile;

    public function __construct(
        AiService $aiService,
        PhotoStorageService $photoStorage,
        ReportWizardService $reportWizard
    ) {
        parent::__construct();
        $this->aiService    = $aiService;
        $this->photoStorage = $photoStorage;
        $this->reportWizard = $reportWizard;
        $this->offsetFile   = storage_path('app/telegram_offset.txt');
        $this->lockFile     = storage_path('app/telegram_poll.lock');
        $this->stopFile     = storage_path('app/telegram_poll.stop');
    }

    /**
     * Entry point command — routing berdasarkan option --action.
     *
     * @return int|null
     */
    public function handle()
    {
        $action = $this->option('action');

        if ($action === 'status') {
            return $this->showStatus();
        }

        if ($action === 'stop') {
            return $this->stopPolling();
        }

        // Default: mulai polling
        $this->startPolling();
    }

    /**
     * Loop utama long polling Telegram.
     *
     * Berjalan terus-menerus hingga stop signal muncul (file stopFile)
     * atau Ctrl+C ditekan. Setiap iterasi memproses semua update yang masuk,
     * menyimpan offset, dan memperbarui lock file.
     *
     * @return int|null
     */
    private function startPolling()
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            $this->error('TELEGRAM_BOT_TOKEN tidak dikonfigurasi di .env');
            return Command::FAILURE;
        }

        // Hapus stop signal jika ada sisa dari sesi sebelumnya
        if (file_exists($this->stopFile)) {
            @unlink($this->stopFile);
        }

        $this->info('Memulai long polling Telegram...');
        $this->info('Untuk menghentikan: touch ' . $this->stopFile);
        $this->info('Tekan Ctrl+C untuk berhenti.');
        $this->newLine();

        $this->updateLock();

        $offset         = $this->getLastOffset();
        $processedCount = 0;
        $startTime      = time();

        while (true) {
            // Cek stop signal
            if (file_exists($this->stopFile)) {
                $this->info('Stop signal detected. Menghentikan polling...');
                @unlink($this->stopFile);
                @unlink($this->lockFile);
                break;
            }

            try {
                $response = Http::timeout(60)->post("https://api.telegram.org/bot{$token}/getUpdates", [
                    'offset'          => $offset,
                    'timeout'         => 30,
                    'allowed_updates' => ['message', 'callback_query'],
                ]);

                if (!$response->successful()) {
                    $this->warn("Gagal getUpdates: " . $response->body());
                    sleep(5);
                    continue;
                }

                $updates = $response->json()['result'] ?? [];

                foreach ($updates as $update) {
                    $updateId = $update['update_id'];
                    $offset   = $updateId + 1;

                    if (isset($update['callback_query'])) {
                        $this->processCallbackQuery($update);
                        $processedCount++;
                    } elseif (isset($update['message'])) {
                        $this->processUpdate($update);
                        $processedCount++;
                    }
                }

                $this->saveLastOffset($offset);
                $this->updateLock();

                // Tampilkan status setiap 30 detik
                if (time() - $startTime >= 30) {
                    $uptime    = gmdate('H:i:s', time() - $startTime);
                    $this->info("[{$uptime}] Polling aktif — {$processedCount} pesan diproses");
                    $startTime = time();
                }

            } catch (\Exception $e) {
                $this->warn("Error: " . $e->getMessage());
                sleep(3);
            }
        }
    }

    /**
     * Proses callback query dari inline keyboard.
     *
     * Melakukan deduplication via Cache (60 detik) untuk mencegah double-processing
     * jika Telegram mengirim callback yang sama lebih dari sekali.
     * Jika tidak ada wizard aktif, keyboard lama dibersihkan dan teknisi diberi tahu.
     *
     * @param array $update Update mentah dari Telegram getUpdates
     * @return void
     */
    private function processCallbackQuery(array $update): void
    {
        $callback   = $update['callback_query'];
        $callbackId = $callback['id'];
        $chatId     = $callback['message']['chat']['id'];
        $messageId  = $callback['message']['message_id'];
        $data       = $callback['data'] ?? '';

        // Cek duplikasi callback
        $cacheKey = 'telegram_cb_' . $callbackId;
        if (Cache::has($cacheKey)) {
            $this->line("Duplicate callback skipped: {$callbackId}");
            return;
        }
        Cache::put($cacheKey, true, 60);

        $this->line("Callback: {$data}");

        // Jawab callback agar loading state hilang dari tombol
        $this->answerCallbackQuery($callbackId);

        try {
            // ReportWizardService adalah satu-satunya sumber kebenaran untuk callback.
            // Alur klarifikasi berdiri sendiri (ClarificationService) sudah sepenuhnya
            // digantikan oleh wizard sejak F4/F5.
            if ($this->reportWizard->hasActiveWizard((string) $chatId)) {
                $this->handleWizardCallback($chatId, $messageId, $data);
                return;
            }

            // Tidak ada wizard aktif — tombol berasal dari pesan lama yang sesinya
            // sudah berakhir/timeout. Bersihkan keyboard agar tidak bisa dipencet berulang.
            $this->editMessageTextSimple(
                $chatId,
                $messageId,
                "Sesi laporan ini sudah tidak aktif. Silakan kirim pesan baru untuk membuat laporan."
            );
        } catch (\Exception $e) {
            Log::error("Callback error: " . $e->getMessage());
            $this->sendMessage($chatId, "Terjadi kesalahan. Silakan coba lagi.");
        }
    }

    // =========================================================
    // State management
    // =========================================================

    /**
     * Baca offset update Telegram terakhir dari file storage.
     *
     * @return int Offset terakhir, atau 0 jika file belum ada
     */
    private function getLastOffset(): int
    {
        if (file_exists($this->offsetFile)) {
            return (int) file_get_contents($this->offsetFile);
        }
        return 0;
    }

    /**
     * Simpan offset update Telegram terakhir ke file storage.
     *
     * @param int $offset Nilai offset yang akan disimpan
     * @return void
     */
    private function saveLastOffset(int $offset): void
    {
        file_put_contents($this->offsetFile, $offset);
    }

    /**
     * Perbarui lock file dengan timestamp saat ini.
     *
     * Dipakai untuk menandai bahwa proses polling masih hidup.
     *
     * @return void
     */
    private function updateLock(): void
    {
        file_put_contents($this->lockFile, time());
    }

    // =========================================================
    // Status dan kontrol
    // =========================================================

    /**
     * Tampilkan informasi status polling ke console.
     *
     * @return int Command::SUCCESS | Command::FAILURE
     */
    private function showStatus(): int
    {
        $this->info('Status Polling Telegram');
        $this->newLine();

        $token = config('services.telegram.bot_token');
        if (empty($token)) {
            $this->warn('Token bot tidak dikonfigurasi');
            return Command::FAILURE;
        }

        $this->line("Token: " . substr($token, 0, 20) . '...');
        $this->line("Offset file: " . ($this->getLastOffset() ?: '0 (mulai awal)'));
        $this->line("Teknisi aktif: " . Technician::where('status', 'active')->count());
        $this->line("Pesan antri: " . Technician::whereNotNull('telegram_id')->count() . " teknisi terdaftar");

        return Command::SUCCESS;
    }

    /**
     * Hentikan polling dan reset offset ke 0.
     *
     * Reset offset menyebabkan polling mulai dari awal saat dijalankan kembali.
     *
     * @return int Command::SUCCESS
     */
    private function stopPolling(): int
    {
        $this->info('Menghentikan polling...');
        $this->saveLastOffset(0);
        $this->info('Offset di-reset ke 0. Polling akan mulai dari awal saat dijalankan lagi.');
        return Command::SUCCESS;
    }
}
