<?php

namespace App\Console\Commands\Traits;

use App\Models\BotRegistration;
use App\Models\Technician;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Trait TelegramMessageHandlerTrait
 *
 * Digunakan oleh PollTelegramUpdates untuk routing dan pemrosesan semua
 * pesan masuk dari Telegram (teks, foto, dan navigasi internal wizard).
 *
 * Trait ini bergantung pada TelegramSenderTrait (untuk mengirim balasan)
 * dan properti $reportWizard serta $photoStorage yang didefinisikan
 * di PollTelegramUpdates.
 *
 * Method yang tersedia:
 *   - processUpdate()            : Entry point routing pesan teks dan foto
 *   - handlePhotoMessage()       : Routing pesan foto (wizard / RPT code / wizard baru)
 *   - handleWizardText()         : Teruskan teks ke wizard aktif
 *   - handleWizardCallback()     : Teruskan callback ke wizard aktif
 *   - dispatchWizardResponse()   : Kirim balasan wizard ke Telegram
 *   - handleStart()              : Proses perintah /start dan pendaftaran awal
 *   - handleNikRegistration()    : Simpan NIK teknisi yang mendaftar
 *   - handleReport()             : Mulai wizard laporan baru
 */
trait TelegramMessageHandlerTrait
{
    /**
     * Proses satu update pesan dari Telegram (routing utama).
     *
     * Urutan routing:
     *   1. /start command
     *   2. NIK registration (pola "NIK 123456")
     *   3. Validasi teknisi terdaftar & aktif
     *   4. Foto → handlePhotoMessage
     *   5. Teks: wizard aktif → handleWizardText, selain itu → handleReport
     *
     * @param array $update Update mentah dari Telegram getUpdates
     * @return void
     */
    private function processUpdate(array $update): void
    {
        $message    = $update['message'];
        $chatId     = $message['chat']['id'];
        $text       = $message['text'] ?? '';
        $from       = $message['from'] ?? [];
        $telegramId = (string) ($from['id'] ?? '');
        $username   = $from['username'] ?? null;
        $firstName  = $from['first_name'] ?? '';
        $hasPhoto   = !empty($message['photo']);
        $caption    = $message['caption'] ?? '';

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

        // Perbarui waktu aktif terakhir
        $technician->update(['last_active_at' => now()]);

        // Routing foto
        if ($hasPhoto) {
            $this->handlePhotoMessage($chatId, $message, $technician, $caption);
            return;
        }

        // Routing teks: wizard aktif atau mulai baru
        if ($this->reportWizard->hasActiveWizard((string) $chatId)) {
            $this->handleWizardText($chatId, $text);
        } else {
            $this->handleReport($chatId, $technician, $text);
        }
    }

    /**
     * Handle pesan foto dari teknisi.
     *
     * Tiga kemungkinan alur:
     *   A) Wizard aktif di step foto → teruskan ke wizard (download + simpan dulu)
     *   B) Caption mengandung kode RPT-... → tambah foto ke laporan lama
     *   C) Tidak keduanya → mulai wizard baru, foto sebagai foto awal Step 1
     *
     * @param int|string $chatId     ID chat pengirim
     * @param array      $message    Pesan lengkap dari Telegram
     * @param Technician $technician Objek teknisi yang mengirim
     * @param string     $caption    Caption foto (bisa kosong)
     * @return void
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

        // A) Wizard aktif — download & teruskan ke wizard
        if ($this->reportWizard->hasActiveWizard((string) $chatId)) {
            $path = $this->photoStorage->store($fileId, (string) $chatId);

            if ($path === null) {
                $this->sendMessage($chatId, "Gagal menyimpan foto. Coba kirim ulang.");
                return;
            }

            $response = $this->reportWizard->handlePhotoInput((string) $chatId, $path, $caption);
            $this->dispatchWizardResponse($chatId, $response);
            return;
        }

        // B) Caption mengandung kode RPT-... → tambah foto ke laporan lama
        $reportCode = $this->reportWizard->extractReportCode($caption);
        if ($reportCode) {
            $path = $this->photoStorage->store($fileId, (string) $chatId);

            if ($path === null) {
                $this->sendMessage($chatId, "Gagal menyimpan foto untuk laporan {$reportCode}. Coba kirim ulang.");
                return;
            }

            $response = $this->reportWizard->addPhotoToReport($reportCode, $path, $caption);

            // Laporan tidak ditemukan — hapus foto yang sudah terlanjur disimpan
            // agar tidak jadi file yatim di storage.
            if (!empty($response['error'])) {
                $this->photoStorage->delete($path);
            }

            $this->sendMessage($chatId, $response['message'] ?? 'Foto ditambahkan.');
            return;
        }

        // C) Tidak ada wizard aktif & tidak ada RPT code → mulai wizard baru
        // Foto akan menjadi foto awal dan dikonfirmasi di Step 6
        $response = $this->reportWizard->startWizard(
            chatId:      (string) $chatId,
            text:        $caption ?: 'Laporan dengan foto',
            photoFileId: $fileId   // file_id diteruskan, download terjadi di Step 6
        );
        $this->dispatchWizardResponse($chatId, $response);
    }

    /**
     * Teruskan teks ke ReportWizardService saat wizard sedang aktif.
     *
     * @param int|string $chatId ID chat
     * @param string     $text   Teks yang dikirim teknisi
     * @return void
     */
    private function handleWizardText(int|string $chatId, string $text): void
    {
        $this->sendChatAction($chatId);
        $response = $this->reportWizard->handleTextInput((string) $chatId, $text);
        $this->dispatchWizardResponse($chatId, $response);
    }

    /**
     * Teruskan callback ke ReportWizardService saat wizard sedang aktif.
     *
     * Dipanggil dari processCallbackQuery. Jika respons mengandung keyboard,
     * pesan diedit in-place; jika tidak, hanya teks yang diperbarui.
     *
     * Jika laporan berhasil disimpan (saved === true), ReportWizardService
     * mengembalikan dua pesan terpisah untuk mencegah pesan ganda:
     *   - edit_message    : dipakai untuk menimpa pesan konfirmasi Step 8
     *   - success_message : dikirim sebagai pesan baru berisi detail laporan
     * Untuk respons biasa (bukan hasil penyimpanan), hanya field 'message'
     * yang tersedia dan dipakai sebagai fallback untuk edit_message.
     *
     * @param int|string $chatId    ID chat
     * @param int        $messageId ID pesan yang memicu callback
     * @param string     $data      Data callback dari tombol inline keyboard
     * @return void
     */
    private function handleWizardCallback(int|string $chatId, int $messageId, string $data): void
    {
        $response = $this->reportWizard->handleCallback((string) $chatId, $data);

        // Pesan untuk menimpa pesan lama (inline keyboard). Fallback ke 'message'
        // untuk respons wizard biasa yang belum memisahkan edit_message/success_message.
        $editMessage = $response['edit_message'] ?? $response['message'] ?? '';

        if (!empty($editMessage)) {
            if (!empty($response['keyboard'])) {
                $this->editMessageText($chatId, $messageId, $editMessage, $response['keyboard']);
            } else {
                $this->editMessageTextSimple($chatId, $messageId, $editMessage);
            }
        }

        // Jika laporan berhasil disimpan, kirim pesan sukses terpisah (pesan baru).
        // Menggunakan success_message agar tidak identik dengan edit_message di atas.
        if (!empty($response['saved']) && !empty($response['report_code'])) {
            $successMessage = $response['success_message'] ?? $response['message'] ?? "Laporan tersimpan.";
            $this->sendMessage($chatId, $successMessage);
        }
    }

    /**
     * Dispatch respons dari wizard ke Telegram.
     *
     * Pesan wizard selalu dikirim sebagai pesan baru (bukan edit) karena
     * tiap step membuka konteks baru.
     *
     * @param int|string $chatId   ID chat tujuan
     * @param array      $response Respons dari ReportWizardService
     * @return void
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

    /**
     * Proses perintah /start dan pendaftaran teknisi baru.
     *
     * Alur:
     *   - Jika sudah terdaftar & aktif → sambut
     *   - Jika sudah terdaftar & pending → beri tahu status
     *   - Jika ada pendaftaran pending → beri tahu masih diproses
     *   - Jika belum ada → buat BotRegistration pending dan minta NIK
     *
     * @param int|string  $chatId     ID chat
     * @param string      $telegramId Telegram user ID
     * @param string|null $username   Username Telegram (bisa null)
     * @param string      $firstName  Nama depan pengguna
     * @return void
     */
    private function handleStart(
        int|string $chatId,
        string $telegramId,
        ?string $username,
        string $firstName
    ): void {
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

    /**
     * Simpan NIK teknisi ke pendaftaran yang sedang menunggu.
     *
     * @param int|string $chatId     ID chat
     * @param string     $telegramId Telegram user ID
     * @param string     $nik        NIK yang dikirim teknisi
     * @return void
     */
    private function handleNikRegistration(int|string $chatId, string $telegramId, string $nik): void
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

    /**
     * Mulai wizard laporan baru dari teks yang dikirim teknisi.
     *
     * Laporan hanya disimpan ke DB setelah teknisi mengonfirmasi di Step 8.
     * Method ini tipis secara sengaja — semua logika ada di ReportWizardService.
     *
     * @param int|string $chatId     ID chat
     * @param Technician $technician Objek teknisi pengirim
     * @param string     $text       Teks laporan awal
     * @return void
     */
    private function handleReport(int|string $chatId, Technician $technician, string $text): void
    {
        $this->sendChatAction($chatId);
        $response = $this->reportWizard->startWizard((string) $chatId, $text);
        $this->dispatchWizardResponse($chatId, $response);
    }
}
