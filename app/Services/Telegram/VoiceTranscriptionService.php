<?php

namespace App\Services\Telegram;

use App\Models\Technician;
use App\Models\VoiceTranscription;
use App\Services\SpeechToTextService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alur pesan suara Telegram: unduh, transkripsi, konfirmasi, lalu proses
 * sebagai teks biasa bila disetujui.
 *
 * Voice tidak dipakai saat langkah wizard membutuhkan foto. Perintah slash
 * tetap diketik; transkrip hanya menggantikan isian teks bebas.
 */
class VoiceTranscriptionService
{
    public function __construct(
        private VoiceStorageService $storage,
        private SpeechToTextService $speech,
        private PendingTranscriptService $pending,
        private ReportWizardService $reportWizard,
        private SparepartRequestWizardService $requestWizard,
        private TelegramService $telegram
    ) {
    }

    /**
     * Proses voice note yang baru masuk.
     *
     * @param int|string           $chatId
     * @param Technician           $teknisi
     * @param array<string,mixed>  $voice  Elemen message.voice dari Telegram
     * @param bool                 $dukungCallback False untuk jalur webhook
     */
    public function handleVoice(int|string $chatId, Technician $teknisi, array $voice, bool $dukungCallback = true): void
    {
        if (! config('voice.enabled', true)) {
            $this->telegram->sendMessage($chatId, 'Transkripsi suara sedang dinonaktifkan.');

            return;
        }

        $chat = (string) $chatId;

        if ($this->reportWizard->menungguFoto($chat) || $this->requestWizard->menungguFoto($chat)) {
            $this->telegram->sendMessage(
                $chatId,
                "Langkah ini membutuhkan foto, bukan suara.\n"
                . "Kirim foto, atau ketik - untuk melewati langkah foto."
            );

            return;
        }

        if (! $this->bolehMemproses($teknisi)) {
            $this->telegram->sendMessage($chatId, 'Terlalu banyak pesan suara. Tunggu sebentar lalu coba lagi.');

            return;
        }

        $durasi = (int) ($voice['duration'] ?? 0);
        $maksDurasi = (int) config('voice.max_durasi_detik', 300);

        if ($durasi > $maksDurasi) {
            $this->telegram->sendMessage(
                $chatId,
                'Voice note terlalu panjang. Maksimal ' . $maksDurasi . ' detik.'
            );

            return;
        }

        $fileId = (string) ($voice['file_id'] ?? '');

        if ($fileId === '') {
            $this->telegram->sendMessage($chatId, 'Voice note tidak bisa dibaca. Coba kirim ulang.');

            return;
        }

        $tersimpan = $this->storage->store($fileId, $chat);

        if ($tersimpan === null) {
            $this->catat($teknisi, $chat, $durasi, 'gagal', null, null, null, null, 'Gagal mengunduh audio.');

            $this->telegram->sendMessage($chatId, 'Gagal mengunduh voice note. Coba kirim ulang.');

            return;
        }

        $hasil = $this->speech->transcribe($tersimpan['path'], $durasi);

        // Audio tidak pernah disimpan permanen.
        $this->storage->delete($tersimpan['path']);

        if (! ($hasil['ok'] ?? false)) {
            $galat = (string) ($hasil['error'] ?? 'Transkripsi gagal.');
            $this->catat($teknisi, $chat, $durasi, 'gagal', null, null, null, null, $galat);

            $this->telegram->sendMessage($chatId, $galat . "\nCoba ulangi atau ketik pesan Anda.");

            return;
        }

        $this->pending->simpan($chat, $teknisi, $hasil, $dukungCallback);

        $this->catat(
            $teknisi,
            $chat,
            (int) ($hasil['duration'] ?? $durasi),
            'sukses',
            $hasil['text'] ?? null,
            $hasil['language'] ?? null,
            $hasil['confidence'] ?? null,
            (int) ($hasil['latency_ms'] ?? 0),
            null,
            (string) ($hasil['model'] ?? '')
        );

        $this->kirimKonfirmasi($chatId, $hasil, $dukungCallback);
    }

    /**
     * Tangani balasan teks GUNAKAN/ULANGI/BATAL saat jalur webhook tidak
     * mendukung inline keyboard.
     *
     * @return bool True bila teks sudah ditangani sebagai keputusan transkrip
     */
    public function handleTextDecision(int|string $chatId, Technician $teknisi, string $text): bool
    {
        $chat = (string) $chatId;
        $data = $this->pending->ambil($chat);

        if ($data === null || (int) ($data['technician_id'] ?? 0) !== (int) $teknisi->id) {
            return false;
        }

        $perintah = strtolower(trim($text));

        // Mode koreksi: teks berikutnya menggantikan transkrip, bukan diproses
        // sebagai isi laporan.
        if (($data['mode'] ?? 'confirm') === 'edit') {
            $baru = $this->pending->gantiTeks($chat, $text);

            if ($baru !== null) {
                $this->kirimKonfirmasi(
                    $chatId,
                    $baru,
                    (bool) ($baru['dukung_callback'] ?? true)
                );
            }

            return true;
        }

        if (in_array($perintah, ['koreksi', 'edit'], true)) {
            $this->pending->setMode($chat, 'edit');
            $this->telegram->sendMessage($chatId, 'Ketik teks yang benar. Teks yang Anda kirim akan menggantikan transkrip.');

            return true;
        }

        if (in_array($perintah, ['gunakan', 'pakai'], true)) {
            $this->pending->hapus($chat);
            $this->prosesTranskrip($chatId, $teknisi, (string) ($data['text'] ?? ''));

            return true;
        }

        if (in_array($perintah, ['ulangi', 'ulang'], true)) {
            $this->pending->hapus($chat);
            $this->telegram->sendMessage($chatId, 'Silakan kirim voice note lagi.');

            return true;
        }

        if (in_array($perintah, ['batal', 'batalkan'], true)) {
            $this->pending->hapus($chat);
            $this->telegram->sendMessage($chatId, 'Transkrip dibatalkan.');

            return true;
        }

        // Teks lain dianggap koreksi/pesan baru; transkrip lama dibuang.
        $this->pending->hapus($chat);

        return false;
    }

    /**
     * Tangani callback inline Gunakan/Ulangi/Batalkan.
     *
     * @param array<string,mixed> $callback
     */
    public function handleCallback(array $callback, int|string $chatId, int $messageId): void
    {
        $data    = (string) ($callback['data'] ?? '');
        $fromId  = (string) ($callback['from']['id'] ?? '');
        $teknisi = Technician::query()
            ->where('telegram_id', $fromId)
            ->where('status', 'active')
            ->first();

        if ($teknisi === null) {
            $this->telegram->editMessageText($chatId, $messageId, 'Akun Telegram ini belum terdaftar sebagai teknisi aktif.');

            return;
        }

        $chat = (string) $chatId;
        $pending = $this->pending->ambil($chat);

        if ($pending === null || (int) ($pending['technician_id'] ?? 0) !== (int) $teknisi->id) {
            $this->telegram->editMessageText($chatId, $messageId, 'Transkrip ini sudah tidak berlaku. Kirim voice note baru.');

            return;
        }

        if ($data === 'suara:pakai') {
            $this->pending->hapus($chat);
            $this->telegram->editMessageText($chatId, $messageId, 'Transkrip digunakan.');
            $this->prosesTranskrip($chatId, $teknisi, (string) ($pending['text'] ?? ''));

            return;
        }

        if ($data === 'suara:edit') {
            $this->pending->setMode($chat, 'edit');
            $this->telegram->editMessageText($chatId, $messageId, 'Ketik teks yang benar. Teks yang Anda kirim akan menggantikan transkrip.');

            return;
        }

        if ($data === 'suara:ulang') {
            $this->pending->hapus($chat);
            $this->telegram->editMessageText($chatId, $messageId, 'Silakan kirim voice note lagi.');

            return;
        }

        if ($data === 'suara:batal') {
            $this->pending->hapus($chat);
            $this->telegram->editMessageText($chatId, $messageId, 'Transkrip dibatalkan.');

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, 'Pilihan tidak dikenal. Kirim voice note baru bila perlu.');
    }

    /**
     * Kirim transkrip dan tombol keputusan.
     *
     * @param array<string,mixed> $hasil
     */
    private function kirimKonfirmasi(int|string $chatId, array $hasil, bool $dukungCallback): void
    {
        $teks      = (string) ($hasil['text'] ?? '');
        $keyakinan = (string) ($hasil['confidence'] ?? 'sedang');
        $durasi    = (int) ($hasil['duration'] ?? 0);
        $label     = $keyakinan === 'koreksi' ? 'hasil koreksi' : 'keyakinan: ' . $keyakinan;

        $pesan = "Transkrip suara (" . e($label) . "):\n\n"
            . e($teks) . "\n\n"
            . 'Durasi audio: ' . $durasi . " detik.\n"
            . 'Gunakan teks ini?';

        if (! $dukungCallback) {
            $pesan .= "\nBalas GUNAKAN, KOREKSI, ULANGI, atau BATAL.";

            $this->telegram->sendMessage($chatId, $pesan);

            return;
        }

        $this->telegram->sendMessage($chatId, $pesan, [
            'reply_markup' => ['inline_keyboard' => [
                [
                    ['text' => 'Gunakan', 'callback_data' => 'suara:pakai'],
                    ['text' => 'Ketik koreksi', 'callback_data' => 'suara:edit'],
                ],
                [
                    ['text' => 'Ulangi', 'callback_data' => 'suara:ulang'],
                    ['text' => 'Batalkan', 'callback_data' => 'suara:batal'],
                ],
            ]],
        ]);
    }

    /**
     * Proses transkrip yang disetujui sebagai teks biasa.
     */
    private function prosesTranskrip(int|string $chatId, Technician $teknisi, string $text): void
    {
        if ($text === '') {
            $this->telegram->sendMessage($chatId, 'Transkrip kosong. Kirim voice note lagi.');

            return;
        }

        $chat = (string) $chatId;

        if ($this->reportWizard->menungguFoto($chat) || $this->requestWizard->menungguFoto($chat)) {
            $this->telegram->sendMessage(
                $chatId,
                "Langkah ini sekarang membutuhkan foto. Kirim foto, atau ketik - untuk melewati."
            );

            return;
        }

        if ($this->requestWizard->hasActive($chat)) {
            $this->kirimResponsWizard($chatId, $this->requestWizard->handleText($chat, $text));

            return;
        }

        if ($this->reportWizard->hasActiveWizard($chat)) {
            if ($this->reportWizard->batalkanSesiBasi($chat)) {
                $this->telegram->sendMessage(
                    $chatId,
                    'Sesi laporan sebelumnya dibatalkan otomatis karena tidak ada jawaban lebih dari '
                    . $this->reportWizard->batasIdleMenit() . " menit.\n"
                    . 'Transkrip suara sekarang diproses sebagai laporan baru.'
                );

                $this->kirimResponsWizard($chatId, $this->reportWizard->startWizard($chat, $text));

                return;
            }

            $this->kirimResponsWizard($chatId, $this->reportWizard->handleTextInput($chat, $text));

            return;
        }

        $this->kirimResponsWizard($chatId, $this->reportWizard->startWizard($chat, $text));
    }

    /**
     * Kirim respons wizard memakai format Markdown yang sama dengan poller.
     *
     * @param array<string,mixed> $response
     */
    private function kirimResponsWizard(int|string $chatId, array $response): void
    {
        if (($response['saved'] ?? false) === true) {
            $pesan = (string) ($response['success_message'] ?? $response['edit_message'] ?? '');

            if ($pesan !== '') {
                $this->telegram->sendMessage($chatId, $pesan, ['parse_mode' => 'Markdown']);
            }

            return;
        }

        $pesan    = (string) ($response['message'] ?? $response['edit_message'] ?? '');
        $keyboard = (array) ($response['keyboard'] ?? []);

        if ($pesan === '') {
            return;
        }

        $extra = ['parse_mode' => 'Markdown'];
        $baris = [];

        foreach ($keyboard as $tombol) {
            if (is_string($tombol)) {
                $baris[] = [['text' => $tombol, 'callback_data' => 'clarify_' . md5($tombol)]];
            } elseif (isset($tombol['text'], $tombol['callback_data'])) {
                $baris[] = [[
                    'text'          => (string) $tombol['text'],
                    'callback_data' => (string) $tombol['callback_data'],
                ]];
            }
        }

        if ($baris !== []) {
            $extra['reply_markup'] = ['inline_keyboard' => $baris];
        }

        $this->telegram->sendMessage($chatId, $pesan, $extra);
    }

    /**
     * Batasi jumlah permintaan transkripsi per menit.
     */
    private function bolehMemproses(Technician $teknisi): bool
    {
        $batas = max(1, (int) config('voice.rate_limit_per_menit', 5));
        $kunci = 'voice_rate:' . $teknisi->id;
        $jumlah = (int) Cache::get($kunci, 0);

        if ($jumlah >= $batas) {
            return false;
        }

        Cache::put($kunci, $jumlah + 1, now()->addMinute());

        return true;
    }

    /**
     * Simpan log transkripsi (tanpa audio).
     */
    private function catat(
        Technician $teknisi,
        string $chatId,
        int $durasi,
        string $status,
        ?string $transcript,
        ?string $language,
        ?string $confidence,
        ?int $latencyMs,
        ?string $error,
        string $model = ''
    ): void {
        try {
            VoiceTranscription::create([
                'technician_id'    => $teknisi->id,
                'chat_id'          => $chatId,
                'duration_seconds' => $durasi > 0 ? $durasi : null,
                'model'            => $model !== '' ? $model : (string) config('voice.model', 'whisper-large-v3-turbo'),
                'language'         => $language,
                'confidence'       => $confidence,
                'transcript'       => $transcript,
                'latency_ms'       => $latencyMs !== null && $latencyMs > 0 ? $latencyMs : null,
                'status'           => $status,
                'error'            => $error,
            ]);
        } catch (\Throwable $galat) {
            Log::warning('VoiceTranscription: gagal mencatat log', ['error' => $galat->getMessage()]);
        }
    }
}
