<?php

namespace App\Console\Commands;

use App\Models\BotRegistration;
use App\Models\Technician;
use App\Services\AiService;
use App\Services\Telegram\PhotoStorageService;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PollTelegramUpdates extends Command
{
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

    public function handle()
    {
        $action = $this->option('action');

        if ($action === 'status') {
            return $this->showStatus();
        }

        if ($action === 'stop') {
            return $this->stopPolling();
        }

        // Default: start polling
        $this->startPolling();
    }

    private function startPolling()
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            $this->error('TELEGRAM_BOT_TOKEN tidak dikonfigurasi di .env');
            return Command::FAILURE;
        }

        // Hapus stop signal jika ada
        if (file_exists($this->stopFile)) {
            @unlink($this->stopFile);
        }

        $this->info('Memulai long polling Telegram...');
        $this->info('Untuk menghentikan: touch ' . $this->stopFile);
        $this->info('Tekan Ctrl+C untuk berhenti.');
        $this->newLine();

        // Tulis lock file
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

                // Simpan offset terakhir
                $this->saveLastOffset($offset);

                // Update lock file setiap iterasi
                $this->updateLock();

                // Tampilkan status setiap 30 detik
                if (time() - $startTime >= 30) {
                    $uptime = gmdate('H:i:s', time() - $startTime);
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
     * Handle callback query dari inline keyboard
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

        // Jawab callback (hilangkan loading)
        $this->answerCallbackQuery($callbackId);

        try {
            // === F6: ReportWizardService adalah satu-satunya sumber kebenaran
            // untuk callback. Alur klarifikasi berdiri sendiri (ClarificationService)
            // sudah sepenuhnya digantikan oleh wizard sejak F4/F5. ===
            if ($this->reportWizard->hasActiveWizard((string)$chatId)) {
                $this->handleWizardCallback($chatId, $messageId, $data);
                return;
            }

            // Tidak ada wizard aktif untuk chat ini — tombol berasal dari pesan
            // lama yang sesinya sudah berakhir/timeout. Bersihkan keyboard agar
            // tidak bisa dipencet berulang dan beri tahu teknisi.
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

    /**
     * Jawab callback query (hilangkan loading state)
     */
    private function answerCallbackQuery(string $callbackId): void
    {
        $token = config('services.telegram.bot_token');
        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
            ]);
        } catch (\Exception $e) {
            // Abaikan
        }
    }

    /**
     * Edit pesan dengan keyboard baru
     */
    private function editMessageText($chatId, int $messageId, string $text, array $buttons = []): void
    {
        $token    = config('services.telegram.bot_token');
        $keyboard = [];

        foreach ($buttons as $btn) {
            $keyboard[] = [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]];
        }

        $params = [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            // Selalu sertakan reply_markup — inline_keyboard kosong akan
            // menghapus keyboard lama yang masih menempel di pesan.
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/editMessageText", $params);
        } catch (\Exception $e) {
            Log::error("editMessageText error: " . $e->getMessage());
        }
    }

    /**
     * Edit pesan tanpa keyboard
     */
    private function editMessageTextSimple($chatId, int $messageId, string $text): void
    {
        $this->editMessageText($chatId, $messageId, $text);
    }

    private function processUpdate(array $update)
    {
        $message    = $update['message'];
        $chatId     = $message['chat']['id'];
        $text       = $message['text'] ?? '';
        $from       = $message['from'] ?? [];
        $telegramId = (string)($from['id'] ?? '');
        $username   = $from['username'] ?? null;
        $firstName  = $from['first_name'] ?? '';

        // Deteksi apakah pesan mengandung foto
        $hasPhoto  = !empty($message['photo']);
        $caption   = $message['caption'] ?? '';

        $this->line("Pesan dari {$firstName} (@{$username}): " . Str::limit($text ?: $caption ?: '[foto]', 80));

        // Handle /start
        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId, $telegramId, $username, $firstName);
            return;
        }

        // Handle NIK registration
        if (preg_match('/^NIK\s+(\S+)$/i', $text, $matches)) {
            $this->handleNikRegistration($chatId, $telegramId, $matches[1]);
            return;
        }

        // Cek teknisi terdaftar & aktif
        $technician = Technician::where('telegram_id', $telegramId)
            ->where('status', 'active')
            ->first();

        if (!$technician) {
            $this->sendMessage($chatId, 'Maaf, akun kamu belum terdaftar atau belum disetujui. Silakan hubungi admin.');
            return;
        }

        // Update last active
        $technician->update(['last_active_at' => now()]);

        // === F5: Routing foto ===
        if ($hasPhoto) {
            $this->handlePhotoMessage($chatId, $message, $technician, $caption);
            return;
        }

        // === Routing teks: wizard aktif atau mulai baru ===
        if ($this->reportWizard->hasActiveWizard((string)$chatId)) {
            $this->handleWizardText($chatId, $text);
        } else {
            $this->handleReport($chatId, $technician, $text);
        }
    }

    // =========================================================
    // F5 — HANDLER FOTO & WIZARD ROUTING
    // =========================================================

    /**
     * Handle pesan foto dari teknisi.
     *
     * Tiga kemungkinan:
     * A) Wizard aktif di step foto → tambah ke wizard
     * B) Caption mengandung kode RPT-... → tambah ke laporan lama
     * C) Tidak keduanya → mulai wizard baru (foto jadi foto awal Step 1)
     */
    private function handlePhotoMessage(
        int|string $chatId,
        array $message,
        Technician $technician,
        string $caption
    ): void {
        $this->sendChatAction($chatId);

        // Ambil file_id resolusi tertinggi (foto Telegram dikirim multi-resolusi,
        // array terakhir adalah yang terbesar)
        $photos = $message['photo'] ?? [];
        if (empty($photos)) {
            $this->sendMessage($chatId, "Foto tidak bisa dibaca. Coba kirim ulang.");
            return;
        }
        $bestPhoto = end($photos);
        $fileId    = $bestPhoto['file_id'] ?? null;

        if (!$fileId) {
            $this->sendMessage($chatId, "File ID foto tidak ditemukan. Coba kirim ulang.");
            return;
        }

        // A) Wizard aktif — teruskan ke wizard
        if ($this->reportWizard->hasActiveWizard((string)$chatId)) {
            // Download & simpan dulu baru teruskan path ke wizard
            $path = $this->photoStorage->store($fileId, (string)$chatId);

            if ($path === null) {
                $this->sendMessage($chatId, "Gagal menyimpan foto. Coba kirim ulang.");
                return;
            }

            // Ganti file_id dengan path di state wizard
            $response = $this->reportWizard->handlePhotoInput((string)$chatId, $path, $caption);
            $this->dispatchWizardResponse($chatId, $response);
            return;
        }

        // B) Caption mengandung kode RPT-... → tambah foto ke laporan lama
        $reportCode = $this->reportWizard->extractReportCode($caption);
        if ($reportCode) {
            $path = $this->photoStorage->store($fileId, (string)$chatId);

            if ($path === null) {
                $this->sendMessage($chatId, "Gagal menyimpan foto untuk laporan {$reportCode}. Coba kirim ulang.");
                return;
            }

            $response = $this->reportWizard->addPhotoToReport($reportCode, $path, $caption);

            // Laporan tidak ditemukan — foto sudah terlanjur diunduh ke storage,
            // hapus agar tidak jadi file yatim.
            if (!empty($response['error'])) {
                $this->photoStorage->delete($path);
            }

            $this->sendMessage($chatId, $response['message'] ?? 'Foto ditambahkan.');
            return;
        }

        // C) Tidak ada wizard & tidak ada RPT code → mulai wizard baru dengan foto ini
        // Foto akan menjadi foto awal dan dikonfirmasi di Step 6
        $response = $this->reportWizard->startWizard(
            chatId:      (string)$chatId,
            text:        $caption ?: 'Laporan dengan foto',
            photoFileId: $fileId          // file_id diteruskan, download terjadi di Step 6
        );
        $this->dispatchWizardResponse($chatId, $response);
    }

    /**
     * Teruskan teks ke ReportWizardService saat wizard sedang aktif.
     */
    private function handleWizardText(int|string $chatId, string $text): void
    {
        $this->sendChatAction($chatId);
        $response = $this->reportWizard->handleTextInput((string)$chatId, $text);
        $this->dispatchWizardResponse($chatId, $response);
    }

    /**
     * Teruskan callback ke ReportWizardService saat wizard sedang aktif.
     * Dipanggil dari processCallbackQuery.
     */
    private function handleWizardCallback(int|string $chatId, int $messageId, string $data): void
    {
        $response = $this->reportWizard->handleCallback((string)$chatId, $data);

        if (!empty($response['message'])) {
            if (!empty($response['keyboard'])) {
                $this->editMessageText($chatId, $messageId, $response['message'], $response['keyboard']);
            } else {
                $this->editMessageTextSimple($chatId, $messageId, $response['message']);
            }
        }

        // Jika laporan berhasil disimpan, kirim pesan terpisah dengan kode laporan
        if (!empty($response['saved']) && !empty($response['report_code'])) {
            $this->sendMessage($chatId, $response['message'] ?? "Laporan tersimpan.");
        }
    }

    /**
     * Dispatch respons dari wizard ke Telegram (kirim pesan + keyboard jika ada).
     * Pesan wizard selalu dikirim sebagai pesan baru (bukan edit) karena
     * tiap step membuka konteks baru.
     */
    private function dispatchWizardResponse(int|string $chatId, array $response): void
    {
        $message  = $response['message'] ?? '';
        $keyboard = $response['keyboard'] ?? [];

        if (empty($message)) {
            return;
        }

        if (!empty($keyboard)) {
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard);
        } else {
            $this->sendMessage($chatId, $message);
        }
    }

    private function handleStart($chatId, $telegramId, $username, $firstName)
    {
        $existing = Technician::where('telegram_id', $telegramId)->first();
        if ($existing) {
            if ($existing->status === 'active') {
                $this->sendMessage($chatId, "Halo $firstName! Akun kamu sudah aktif. Silakan kirim laporan harian.");
            } else {
                $this->sendMessage($chatId, "Akun kamu masih menunggu persetujuan admin.");
            }
            return;
        }

        $pending = BotRegistration::where('telegram_id', $telegramId)
            ->where('status', 'pending')
            ->first();

        if ($pending) {
            $this->sendMessage($chatId, "Pendaftaran kamu masih diproses. Silakan tunggu konfirmasi dari admin.");
            return;
        }

        BotRegistration::create([
            'telegram_id'       => $telegramId,
            'telegram_username' => $username,
            'name'              => $firstName,
            'status'            => 'pending',
        ]);

        $this->sendMessage(
            $chatId,
            "Halo $firstName! Untuk mendaftar sebagai teknisi, silakan kirim NIK kamu.\n\nContoh: NIK 123456"
        );
    }

    private function handleNikRegistration($chatId, string $telegramId, string $nik)
    {
        $registration = BotRegistration::where('telegram_id', $telegramId)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($registration) {
            $registration->update(['nik' => $nik]);
            $this->sendMessage(
                $chatId,
                "Terima kasih! NIK kamu ($nik) sudah dicatat. Admin akan memproses pendaftaran kamu segera."
            );
        } else {
            $this->sendMessage($chatId, "Tidak ada pendaftaran yang menunggu untuk NIK. Silakan kirim /start dulu.");
        }
    }

    // =========================================================
    // F5 — handleReport: delegasi ke ReportWizardService
    // Alur lama (simpan draft langsung) digantikan wizard "Create at End".
    // Method ini tetap ada agar mudah di-trace; isinya sekarang tipis.
    // =========================================================
    private function handleReport($chatId, Technician $technician, string $text)
    {
        $this->sendChatAction($chatId);

        // Mulai wizard baru — laporan hanya disimpan setelah konfirmasi Step 8
        $response = $this->reportWizard->startWizard((string)$chatId, $text);
        $this->dispatchWizardResponse($chatId, $response);
    }


    private function sendChatAction($chatId, string $action = 'typing'): void
    {
        $token = config('services.telegram.bot_token');
        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendChatAction", [
                'chat_id' => $chatId,
                'action'  => $action,
            ]);
        } catch (\Exception $e) {
            // Abaikan error
        }
    }

    /**
     * Kirim pesan dengan inline keyboard.
     * $buttons bisa berupa array of strings atau array of ['text' => ..., 'callback_data' => ...]
     */
    private function sendMessageWithKeyboard($chatId, string $text, array $buttons)
    {
        $token    = config('services.telegram.bot_token');
        $keyboard = [];

        foreach ($buttons as $btn) {
            if (is_string($btn)) {
                $keyboard[] = [['text' => $btn, 'callback_data' => 'clarify_' . md5($btn)]];
            } elseif (isset($btn['text']) && isset($btn['callback_data'])) {
                $keyboard[] = [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]];
            }
        }

        if (empty($keyboard)) {
            $this->sendMessage($chatId, $text);
            return;
        }

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
        } catch (\Exception $e) {
            Log::error("Poll sendMessageWithKeyboard error: " . $e->getMessage());
            $this->sendMessage($chatId, $text);
        }
    }

    private function sendMessage($chatId, string $text)
    {
        $token = config('services.telegram.bot_token');

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            Log::error("Poll sendMessage error: " . $e->getMessage());
        }
    }

    private function getLastOffset(): int
    {
        if (file_exists($this->offsetFile)) {
            return (int) file_get_contents($this->offsetFile);
        }
        return 0;
    }

    private function saveLastOffset(int $offset): void
    {
        file_put_contents($this->offsetFile, $offset);
    }

    private function updateLock(): void
    {
        file_put_contents($this->lockFile, time());
    }

    private function showStatus()
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

    private function stopPolling()
    {
        $this->info('Menghentikan polling...');
        // Simpan offset 0 untuk reset
        $this->saveLastOffset(0);
        $this->info('Offset di-reset ke 0. Polling akan mulai dari awal saat dijalankan lagi.');
        return Command::SUCCESS;
    }
}
