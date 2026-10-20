<?php

namespace App\Console\Commands\Traits;

use App\Models\BotRegistration;
use App\Models\Technician;
use App\Services\CutiService;
use App\Services\Telegram\AssetInfoService;
use App\Services\Telegram\PhotoNotificationBuffer;
use Illuminate\Support\Facades\Cache;
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
 *   - handleResetCommand()       : Batalkan sesi wizard lewat perintah /reset
 *   - handleNikRegistration()    : Simpan NIK teknisi yang mendaftar
 *   - handleReport()             : Mulai wizard laporan baru
 */
trait TelegramMessageHandlerTrait
{
    /**
     * Penahan notifikasi foto: satu kiriman berisi banyak foto (media group)
     * hanya menghasilkan satu pesan balasan. Diisi lewat bufferNotifFoto().
     *
     * @var PhotoNotificationBuffer|null
     */
    private ?PhotoNotificationBuffer $photoNotifBuffer = null;

    /**
     * Proses satu update pesan dari Telegram (routing utama).
     *
     * Urutan routing:
     *   1. /start command
     *   2. NIK registration (pola "NIK 123456")
     *   3. Validasi teknisi terdaftar & aktif
     *   4. /info dan /reset command
     *   5. Foto → handlePhotoMessage
     *   6. Teks: wizard aktif → handleWizardText, selain itu → handleReport
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

        // Asisten informasi perangkat: /info [kata kunci]. Berdiri sendiri dan
        // bisa dipakai kapan saja, termasuk saat wizard laporan sedang aktif.
        if (preg_match('/^\/info(?:@\S+)?\b\s*(.*)$/is', $text, $cocokInfo)) {
            $this->handleInfoCommand($chatId, trim($cocokInfo[1] ?? ''));
            return;
        }

        // Perintah /reset: membuang sesi wizard chat ini supaya pesan berikutnya
        // diproses sebagai laporan BARU. Dipakai ketika tombol "Batalkan Laporan"
        // sudah tergulung jauh di riwayat chat (atau layarnya sudah digambar
        // ulang) sehingga teknisi tidak punya jalan keluar selain menunggu sesi
        // basi dibatalkan otomatis oleh batas diam.
        if (preg_match('/^\/reset(?:@\S+)?\b/i', $text)) {
            $this->handleResetCommand($chatId);
            return;
        }

        // Perintah /cuti: buat tautan form cuti sekali pakai. Dicek SEBELUM
        // wizard/laporan supaya /cuti tidak pernah masuk ke klasifikasi
        // pekerjaan. Alasan setelah /cuti ikut disimpan ke form.
        if (preg_match('/^\/cuti(?:@\S+)?(?:\s+(.+))?$/isu', $text, $cocokCuti)) {
            $this->handleCutiCommand($chatId, $technician, trim((string) ($cocokCuti[1] ?? '')));
            return;
        }

        // Routing foto
        if ($hasPhoto) {
            $this->handlePhotoMessage($chatId, $message, $technician, $caption, $message['media_group_id'] ?? null);
            return;
        }

        // Sesi wizard lama yang sudah menganggur dibatalkan lebih dulu supaya
        // teks baru tidak dibaca sebagai jawaban langkah lama (gejala teknisi
        // "tersangkut di sesi/tahap tertentu"). Hanya pesan TEKS yang memicu
        // pembatalan ini; foto dan tombol tetap dilayani apa adanya supaya
        // progres teknisi yang kembali dari lapangan tidak hilang.
        if ($this->reportWizard->batalkanSesiBasi((string) $chatId)) {
            $this->sendMessage(
                $chatId,
                "Sesi laporan sebelumnya dibatalkan otomatis karena tidak ada jawaban lebih dari "
                . $this->reportWizard->batasIdleMenit() . " menit.\n"
                . "Pesan Anda sekarang diproses sebagai laporan baru."
            );
        }

        // Routing teks: wizard aktif atau mulai baru
        if ($this->reportWizard->hasActiveWizard((string) $chatId)) {
            $this->handleWizardText($chatId, $text);
            return;
        }

        // Perintah lanjutan pekerjaan: "<KODE LAPORAN> Done|Continue".
        // Diproses sebelum handleReport() supaya teks ini tidak dianggap
        // laporan baru biasa.
        $perintahLanjutan = $this->reportWizard->detectContinuationCommand($text);

        if ($perintahLanjutan !== null) {
            $this->dispatchWizardResponse($chatId, $this->reportWizard->startContinuation(
                (string) $chatId,
                $perintahLanjutan['kode'],
                $perintahLanjutan['mode']
            ));
            return;
        }

        $this->handleReport($chatId, $technician, $text);
    }

    /**
     * Jalankan perintah /reset: buang sesi wizard chat ini.
     *
     * Alternatif tombol "Batalkan Laporan" bagi teknisi yang tombolnya sudah
     * hilang dari layar (layar lama digambar ulang) atau tergulung jauh di
     * riwayat chat. Setelah state dibuang, pesan berikutnya diproses sebagai
     * laporan baru; tidak ada laporan setengah jalan yang ikut tersimpan karena
     * laporan baru dibuat hanya setelah konfirmasi di Step 8.
     *
     * @param  int|string $chatId ID chat Telegram
     * @return void
     */
    private function handleResetCommand(int|string $chatId): void
    {
        $adaSesi = $this->reportWizard->hasActiveWizard((string) $chatId);

        $this->reportWizard->destroyWizard((string) $chatId);

        if (!$adaSesi) {
            $this->sendMessage($chatId, 'Tidak ada sesi laporan yang aktif. Kirim pesan atau foto untuk memulai laporan baru.');

            return;
        }

        Log::info('TelegramMessageHandler: sesi wizard direset lewat perintah /reset', [
            'chat_id' => (string) $chatId,
        ]);

        $this->sendMessage($chatId, 'Sesi laporan dibatalkan. Kirim pesan atau foto untuk memulai laporan baru.');
    }

    /**
     * Membuat tautan form cuti sekali pakai dan mengirimkannya ke teknisi.
     *
     * @param  int|string $chatId     ID chat Telegram
     * @param  Technician $technician Teknisi pengirim
     * @param  string     $alasan     Alasan dari pesan setelah /cuti
     * @return void
     */
    private function handleCutiCommand(int|string $chatId, Technician $technician, string $alasan): void
    {
        $kunci = 'cuti-tautan:' . $technician->id;

        if (! Cache::add($kunci, true, now()->addSeconds(10))) {
            $this->sendMessage($chatId, 'Tunggu sebentar sebelum meminta tautan cuti lagi.');

            return;
        }

        $tautan = app(CutiService::class)->buatTautan($technician, $alasan);

        $pesan = "Form cuti untuk {$technician->name}:\n"
            . $tautan['url'] . "\n\n"
            . 'Tautan berlaku ' . CutiService::MASA_BERLAKU_MENIT . ' menit dan hanya bisa dipakai sekali.';

        if (trim($alasan) !== '') {
            $pesan .= "\nAlasan dari pesan Anda sudah diisi otomatis di form.";
        }

        $this->sendMessage($chatId, $pesan);
    }

    /**
     * Handle pesan foto dari teknisi.
     *
     * Tiga kemungkinan alur:
     *   A) Wizard aktif di step foto → teruskan ke wizard (download + simpan dulu)
     *   B) Caption mengandung kode RPT-... → tambah foto ke laporan lama
     *   C) Tidak keduanya → mulai wizard baru, foto sebagai foto awal Step 1
     *
     * @param int|string  $chatId       ID chat pengirim
     * @param array       $message      Pesan lengkap dari Telegram
     * @param Technician  $technician   Objek teknisi yang mengirim
     * @param string      $caption      Caption foto (bisa kosong)
     * @param string|null $mediaGroupId ID grup media bila foto dikirim sekaligus
     * @return void
     */
    private function handlePhotoMessage(
        int|string $chatId,
        array $message,
        Technician $technician,
        string $caption,
        ?string $mediaGroupId = null
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
            $this->kirimAtauTahanResponsFoto($chatId, $mediaGroupId, $response);
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

            $this->kirimAtauTahanResponsFoto($chatId, $mediaGroupId, $response);
            return;
        }

        // C) Tidak ada wizard aktif & tidak ada RPT code → mulai wizard baru.
        // Foto harus di-download ke storage lokal terlebih dahulu agar path yang
        // diteruskan ke startWizard() adalah path lokal (bukan raw file_id Telegram).
        // Jika tidak di-download di sini, filterValidLocalPhotoPaths() di saver
        // akan membuang foto karena raw file_id tidak mengandung karakter '/'.
        $localPath = $this->photoStorage->store($fileId, (string) $chatId);

        if ($localPath === null) {
            $this->sendMessage($chatId, "Gagal menyimpan foto awal. Coba kirim ulang.");
            return;
        }

        $response = $this->reportWizard->startWizard(
            chatId:      (string) $chatId,
            text:        $caption ?: 'Laporan dengan foto',
            photoFileId: $localPath
        );

        // Jika wizard gagal dimulai, hapus foto yang sudah terlanjur disimpan
        // agar tidak jadi file yatim di storage.
        if (!empty($response['error'])) {
            $this->photoStorage->delete($localPath);
        }

        $this->kirimAtauTahanResponsFoto($chatId, $mediaGroupId, $response);
    }

    /**
     * Kirim respons foto ke Telegram, atau tahan dulu bila foto datang sebagai
     * bagian dari satu kiriman berisi banyak foto (media group).
     *
     * Penahanan diperlukan karena Telegram mengirim setiap foto dalam satu
     * kiriman sebagai update terpisah — tanpa penahan, teknisi menerima satu
     * pesan balasan per foto. Isi penahan dikirim sekali di akhir batch
     * getUpdates lewat kirimNotifikasiFotoTertahan().
     *
     * @param  int|string   $chatId       ID chat tujuan
     * @param  string|null  $mediaGroupId ID grup media (null bila foto tunggal)
     * @param  array        $response     Respons wizard/pesan biasa
     * @return void
     */
    private function kirimAtauTahanResponsFoto(int|string $chatId, ?string $mediaGroupId, array $response): void
    {
        if ($mediaGroupId !== null && $mediaGroupId !== '') {
            $this->bufferNotifFoto()->tahan($chatId, $mediaGroupId, $response);
            return;
        }

        $this->dispatchWizardResponse($chatId, $response);
    }

    /**
     * Kirim semua notifikasi foto yang ditahan (satu pesan per kiriman foto)
     * lalu kosongkan penahannya. Dipanggil PollTelegramUpdates setelah satu
     * batch getUpdates selesai diproses.
     *
     * @return void
     */
    private function kirimNotifikasiFotoTertahan(): void
    {
        foreach ($this->bufferNotifFoto()->ambilSemua() as $notif) {
            $this->dispatchWizardResponse($notif['chat_id'], [
                'message'  => $notif['message'],
                'keyboard' => $notif['keyboard'],
            ]);
        }
    }

    /**
     * Ambil penahan notifikasi foto (dipakai bersama dalam satu proses polling).
     *
     * @return PhotoNotificationBuffer
     */
    private function bufferNotifFoto(): PhotoNotificationBuffer
    {
        return $this->photoNotifBuffer ??= app(PhotoNotificationBuffer::class);
    }

    /**
     * Jalankan perintah /info: cari perangkat lalu kirim detail atau daftar
     * pilihan. Tanpa kata kunci, pesan yang dikirim adalah pembuka hirarki alat.
     *
     * @param int|string $chatId ID chat
     * @param string     $query  Kata kunci setelah /info (boleh kosong)
     * @return void
     */
    private function handleInfoCommand(int|string $chatId, string $query): void
    {
        $this->sendChatAction($chatId);

        try {
            $respons = app(AssetInfoService::class)->search($query);
        } catch (\Exception $e) {
            Log::error('Perintah /info gagal: ' . $e->getMessage());
            $this->sendMessage($chatId, 'Maaf, pencarian perangkat sedang bermasalah. Coba lagi sebentar.');

            return;
        }

        if (empty($respons['keyboard'])) {
            $this->sendMessage($chatId, $respons['message']);

            return;
        }

        $this->sendMessageWithKeyboard($chatId, $respons['message'], $respons['keyboard']);
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
                $this->sendMessage($chatId, "Halo $firstName! Akun kamu sudah aktif. Silakan kirim laporan harian.\n\nUntuk mencari informasi alat, ketik /info. Contoh:\n/info PC <nama pemakai>\n/info <tag alat>\n/info 192.168.41.210\n/info (tanpa kata kunci) untuk menelusuri hirarki alat");
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
