<?php

namespace App\Services\Telegram;

use App\Models\Technician;
use App\Services\Sparepart\SparepartRequestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Wizard request spare part lewat bot Telegram.
 *
 * State disimpan per chat di cache. Request baru dibuat setelah teknisi
 * menekan tombol Kirim pada langkah konfirmasi, sehingga data setengah jadi
 * tidak pernah masuk database.
 */
class SparepartRequestWizardService
{
    public const CACHE_PREFIX = 'sparepart_request_wizard:';
    public const CACHE_TTL    = 3600;

    public const STEP_KETERANGAN = 'keterangan';
    public const STEP_UKURAN     = 'ukuran';
    public const STEP_JUMLAH     = 'jumlah';
    public const STEP_SATUAN     = 'satuan';
    public const STEP_FOTO       = 'foto';
    public const STEP_KONFIRMASI = 'konfirmasi';

    /**
     * Batas diam sesi request sebelum dianggap basi (menit).
     */
    private const BATAS_IDLE_MENIT = 30;

    public function __construct(
        private readonly SparepartRequestService $layanan,
        private readonly PhotoStorageService $photoStorage
    ) {
    }

    /**
     * Mulai sesi request baru untuk satu chat.
     *
     * @param  string     $chatId
     * @param  Technician $teknisi
     * @param  string     $keterangan Teks setelah /req (boleh kosong)
     * @return array<string, mixed>
     */
    public function mulai(string $chatId, Technician $teknisi, string $keterangan = ''): array
    {
        $stateLama = $this->getState($chatId);

        if ($stateLama !== null) {
            if (! $this->stateBasi($stateLama)) {
                return $this->respons(
                    "Sesi request spare part masih aktif.\n"
                    . "Selesaikan request yang sedang berjalan atau kirim /reset untuk membatalkannya."
                );
            }

            $this->batalkanSesiBasi($chatId);
        }

        $keterangan = trim($keterangan);

        $state = [
            'chat_id'          => $chatId,
            'technician_id'    => $teknisi->id,
            'step'             => $keterangan === '' ? self::STEP_KETERANGAN : self::STEP_UKURAN,
            'keterangan'       => $keterangan,
            'deskripsi_ukuran' => null,
            'jumlah'           => null,
            'satuan'           => null,
            'foto_path'        => null,
            'created_at'       => now()->toIso8601String(),
            'last_activity_at' => now()->toIso8601String(),
        ];

        $this->saveState($chatId, $state);

        return $keterangan === ''
            ? $this->promptKeterangan()
            : $this->promptUkuran($state);
    }

    /**
     * Proses teks yang dikirim saat sesi request aktif.
     *
     * @param  string $chatId
     * @param  string $text
     * @return array<string, mixed>
     */
    public function handleText(string $chatId, string $text): array
    {
        $state = $this->getState($chatId);

        if ($state === null) {
            return $this->respons('Sesi request spare part tidak ditemukan. Mulai lagi dengan /req.');
        }

        if ($this->stateBasi($state)) {
            $this->batalkanSesiBasi($chatId);

            return $this->respons(
                'Sesi request sebelumnya dibatalkan karena tidak ada jawaban lebih dari '
                . self::BATAS_IDLE_MENIT . " menit.\nMulai lagi dengan /req."
            );
        }

        $teks = trim($text);

        if ($state['step'] === self::STEP_KONFIRMASI) {
            $perintah = strtolower($teks);

            if (in_array($perintah, ['kirim', 'simpan', 'ya'], true)) {
                return $this->simpan($chatId, $state);
            }

            if (in_array($perintah, ['batal', 'batalkan', 'tidak'], true)) {
                return $this->batalkan($chatId, $state);
            }

            return $this->promptKonfirmasi($state);
        }

        return match ($state['step']) {
            self::STEP_KETERANGAN => $this->simpanKeterangan($chatId, $state, $teks),
            self::STEP_UKURAN     => $this->simpanUkuran($chatId, $state, $teks),
            self::STEP_JUMLAH     => $this->simpanJumlah($chatId, $state, $teks),
            self::STEP_SATUAN     => $this->simpanSatuan($chatId, $state, $teks),
            self::STEP_FOTO       => $this->lewatiFoto($chatId, $state, $teks),
            default               => $this->respons('Langkah request tidak dikenal. Mulai lagi dengan /req.'),
        };
    }

    /**
     * Simpan foto yang dikirim pada langkah foto.
     *
     * @param  string $chatId
     * @param  string $path Path lokal hasil PhotoStorageService
     * @return array<string, mixed>
     */
    public function handleFoto(string $chatId, string $path): array
    {
        $state = $this->getState($chatId);

        if ($state !== null && $this->stateBasi($state)) {
            $this->batalkanSesiBasi($chatId);
            $this->photoStorage->delete($path);

            return $this->respons(
                'Sesi request sebelumnya dibatalkan karena tidak ada jawaban lebih dari '
                . self::BATAS_IDLE_MENIT . " menit.\nMulai lagi dengan /req."
            );
        }

        if ($state === null || $state['step'] !== self::STEP_FOTO) {
            $this->photoStorage->delete($path);

            return $this->respons('Foto hanya bisa dikirim pada langkah foto. Lanjutkan request atau kirim /reset.');
        }

        if ($state['foto_path'] !== null && $state['foto_path'] !== $path) {
            $this->photoStorage->delete($state['foto_path']);
        }

        $state['foto_path']        = $path;
        $state['step']             = self::STEP_KONFIRMASI;
        $state['last_activity_at'] = now()->toIso8601String();

        $this->saveState($chatId, $state);

        return $this->promptKonfirmasi($state);
    }

    /**
     * Proses callback tombol Kirim/Batalkan.
     *
     * @param  string $chatId
     * @param  string $data
     * @return array<string, mixed>
     */
    public function handleCallback(string $chatId, string $data): array
    {
        $state = $this->getState($chatId);

        if ($state === null) {
            return $this->respons('Sesi request spare part sudah tidak aktif. Mulai lagi dengan /req.');
        }

        if ($this->stateBasi($state)) {
            $this->batalkanSesiBasi($chatId);

            return $this->respons(
                'Sesi request sebelumnya dibatalkan karena tidak ada jawaban lebih dari '
                . self::BATAS_IDLE_MENIT . " menit.\nMulai lagi dengan /req."
            );
        }

        return match ($data) {
            'spr:kirim' => $this->simpan($chatId, $state),
            'spr:batal' => $this->batalkan($chatId, $state),
            default     => $this->respons('Tombol request tidak dikenal.'),
        };
    }

    /**
     * Apakah chat ini sedang punya sesi request aktif.
     *
     * @param  string $chatId
     * @return bool
     */
    public function hasActive(string $chatId): bool
    {
        return Cache::has(self::CACHE_PREFIX . $chatId);
    }

    /**
     * Batalkan sesi yang sudah menganggur terlalu lama.
     *
     * @param  string $chatId
     * @return bool True bila ada sesi basi yang dibatalkan
     */
    public function batalkanSesiBasi(string $chatId): bool
    {
        $state = $this->getState($chatId);

        if ($state === null || ! $this->stateBasi($state)) {
            return false;
        }

        $this->hapusFoto($state);
        Cache::forget(self::CACHE_PREFIX . $chatId);

        Log::info('SparepartRequestWizard: sesi basi dibatalkan', [
            'chat_id' => $chatId,
            'step'    => $state['step'] ?? null,
        ]);

        return true;
    }

    /**
     * Hapus sesi request beserta foto yang belum tersimpan.
     *
     * @param  string $chatId
     * @return void
     */
    public function destroy(string $chatId): void
    {
        $state = $this->getState($chatId);

        if ($state !== null) {
            $this->hapusFoto($state);
        }

        Cache::forget(self::CACHE_PREFIX . $chatId);
    }

    /**
     * State mentah untuk keperluan pengujian atau pemanggil lain.
     *
     * @param  string $chatId
     * @return array<string, mixed>|null
     */
    public function getState(string $chatId): ?array
    {
        $state = Cache::get(self::CACHE_PREFIX . $chatId);

        return is_array($state) ? $state : null;
    }

    /**
     * Simpan state ke cache dan perbarui cap aktivitas.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @return void
     */
    private function saveState(string $chatId, array $state): void
    {
        $state['last_activity_at'] = now()->toIso8601String();

        Cache::put(self::CACHE_PREFIX . $chatId, $state, self::CACHE_TTL);
    }

    /**
     * Simpan keterangan awal request.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @param  string               $teks
     * @return array<string, mixed>
     */
    private function simpanKeterangan(string $chatId, array $state, string $teks): array
    {
        if (mb_strlen($teks) < 3) {
            return $this->respons("Keterangan terlalu pendek. Minimal 3 karakter.\nContoh: Seal pompa bocor.");
        }

        $state['keterangan'] = mb_substr($teks, 0, 1000);
        $state['step']       = self::STEP_UKURAN;
        $this->saveState($chatId, $state);

        return $this->promptUkuran($state);
    }

    /**
     * Simpan deskripsi ukuran opsional.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @param  string               $teks
     * @return array<string, mixed>
     */
    private function simpanUkuran(string $chatId, array $state, string $teks): array
    {
        $state['deskripsi_ukuran'] = ($teks === '' || $teks === '-')
            ? null
            : mb_substr($teks, 0, 500);
        $state['step'] = self::STEP_JUMLAH;
        $this->saveState($chatId, $state);

        return $this->promptJumlah($state);
    }

    /**
     * Validasi dan simpan jumlah.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @param  string               $teks
     * @return array<string, mixed>
     */
    private function simpanJumlah(string $chatId, array $state, string $teks): array
    {
        $angka = str_replace(',', '.', $teks);

        if (! is_numeric($angka) || (float) $angka <= 0) {
            return $this->respons("Jumlah harus berupa angka lebih dari 0.\nContoh: 2 atau 1,5.");
        }

        $state['jumlah'] = (float) $angka;
        $state['step']   = self::STEP_SATUAN;
        $this->saveState($chatId, $state);

        return $this->promptSatuan($state);
    }

    /**
     * Simpan satuan opsional.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @param  string               $teks
     * @return array<string, mixed>
     */
    private function simpanSatuan(string $chatId, array $state, string $teks): array
    {
        $state['satuan'] = ($teks === '' || $teks === '-')
            ? null
            : mb_substr($teks, 0, 30);
        $state['step'] = self::STEP_FOTO;
        $this->saveState($chatId, $state);

        return $this->promptFoto($state);
    }

    /**
     * Lewati langkah foto bila teknisi mengirim tanda hubung.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @param  string               $teks
     * @return array<string, mixed>
     */
    private function lewatiFoto(string $chatId, array $state, string $teks): array
    {
        if ($teks !== '-' && strtolower($teks) !== 'lewat') {
            return $this->respons("Kirim foto barang (opsional) atau ketik - untuk melewati langkah ini.");
        }

        $state['foto_path'] = null;
        $state['step']      = self::STEP_KONFIRMASI;
        $this->saveState($chatId, $state);

        return $this->promptKonfirmasi($state);
    }

    /**
     * Simpan request ke database setelah konfirmasi.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function simpan(string $chatId, array $state): array
    {
        $teknisi = Technician::query()
            ->whereKey($state['technician_id'] ?? null)
            ->where('status', 'active')
            ->first();

        if ($teknisi === null) {
            $this->destroy($chatId);

            return $this->respons('Akun teknisi tidak aktif. Request dibatalkan.');
        }

        try {
            $request = $this->layanan->simpanDariTelegram($teknisi, $state);
        } catch (\Throwable $galat) {
            Log::error('SparepartRequestWizard: gagal menyimpan request', [
                'chat_id' => $chatId,
                'error'   => $galat->getMessage(),
            ]);

            return $this->respons('Request gagal disimpan. Coba kirim KIRIM sekali lagi atau hubungi admin.');
        }

        Cache::forget(self::CACHE_PREFIX . $chatId);

        return $this->respons(
            "Request spare part berhasil dikirim.\n\n"
            . "Kode: {$request->kode}\n"
            . "Status: menunggu persetujuan Staff CM/PM.\n\n"
            . 'Anda akan menerima kabar lewat Telegram saat statusnya berubah.'
        );
    }

    /**
     * Batalkan request dan hapus foto yang belum dipakai.
     *
     * @param  string               $chatId
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function batalkan(string $chatId, array $state): array
    {
        $this->destroy($chatId);

        return $this->respons('Request spare part dibatalkan.');
    }

    /**
     * Prompt awal untuk keterangan.
     *
     * @return array<string, mixed>
     */
    private function promptKeterangan(): array
    {
        return $this->respons(
            "Request spare part baru.\n\n"
            . "Tulis keterangan request.\nContoh: Seal pompa bocor di area 6600.",
            $this->tombolBatal()
        );
    }

    /**
     * Prompt deskripsi ukuran.
     *
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function promptUkuran(array $state): array
    {
        return $this->respons(
            "Keterangan: " . $this->teksAman($state['keterangan']) . "\n\n"
            . "Deskripsi/ukuran (opsional). Ketik - untuk melewati.",
            $this->tombolBatal()
        );
    }

    /**
     * Prompt jumlah.
     *
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function promptJumlah(array $state): array
    {
        $ukuran = $state['deskripsi_ukuran']
            ? "\nUkuran: " . $this->teksAman($state['deskripsi_ukuran'])
            : '';

        return $this->respons(
            "Keterangan: " . $this->teksAman($state['keterangan']) . $ukuran . "\n\n"
            . "Jumlah yang diminta (wajib, angka).\nContoh: 2 atau 1,5.",
            $this->tombolBatal()
        );
    }

    /**
     * Prompt satuan.
     *
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function promptSatuan(array $state): array
    {
        return $this->respons(
            "Jumlah: " . $this->angka($state['jumlah']) . "\n\n"
            . "Satuan (opsional). Contoh: pcs, meter, liter, roll.\nKetik - untuk melewati.",
            $this->tombolBatal()
        );
    }

    /**
     * Prompt foto.
     *
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function promptFoto(array $state): array
    {
        return $this->respons(
            "Jumlah: " . $this->angka($state['jumlah']) . ($state['satuan'] ? ' ' . $this->teksAman($state['satuan']) : '') . "\n\n"
            . "Kirim foto barang (opsional) atau ketik - untuk melewati.",
            $this->tombolBatal()
        );
    }

    /**
     * Ringkasan konfirmasi sebelum request disimpan.
     *
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function promptKonfirmasi(array $state): array
    {
        $baris = [
            'Keterangan: ' . $this->teksAman($state['keterangan']),
            'Ukuran: ' . ($state['deskripsi_ukuran'] ? $this->teksAman($state['deskripsi_ukuran']) : '-'),
            'Jumlah: ' . $this->angka($state['jumlah']) . ($state['satuan'] ? ' ' . $this->teksAman($state['satuan']) : ''),
            'Foto: ' . ($state['foto_path'] ? 'ada' : 'tidak ada'),
        ];

        return $this->respons(
            "Periksa request spare part berikut.\n\n"
            . implode("\n", $baris)
            . "\n\nKetik KIRIM untuk menyimpan atau BATAL untuk membatalkan.",
            [
                ['text' => 'Kirim', 'callback_data' => 'spr:kirim'],
                ['text' => 'Batalkan', 'callback_data' => 'spr:batal'],
            ]
        );
    }

    /**
     * Tombol batal untuk langkah-langkah pengisian.
     *
     * @return array<int, array<string, string>>
     */
    private function tombolBatal(): array
    {
        return [
            ['text' => 'Batalkan', 'callback_data' => 'spr:batal'],
        ];
    }

    /**
     * Bentuk respons standar wizard.
     *
     * @param  string                         $pesan
     * @param  array<int, array<string, string>> $keyboard
     * @return array<string, mixed>
     */
    private function respons(string $pesan, array $keyboard = []): array
    {
        return [
            'message'  => $pesan,
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Apakah state sudah melewati batas diam.
     *
     * @param  array<string, mixed> $state
     * @return bool
     */
    private function stateBasi(array $state): bool
    {
        $terakhir = $state['last_activity_at'] ?? $state['created_at'] ?? null;

        if ($terakhir === null) {
            return false;
        }

        return now()->diffInMinutes($terakhir) >= self::BATAS_IDLE_MENIT;
    }

    /**
     * Hapus foto yang masih tersimpan di state.
     *
     * @param  array<string, mixed> $state
     * @return void
     */
    private function hapusFoto(array $state): void
    {
        if (! empty($state['foto_path'])) {
            $this->photoStorage->delete($state['foto_path']);
        }
    }

    /**
     * Format angka tanpa nol desimal yang tidak perlu.
     *
     * @param  mixed $nilai
     * @return string
     */
    private function angka(mixed $nilai): string
    {
        $teks = number_format((float) $nilai, 2, ',', '.');

        return rtrim(rtrim($teks, '0'), ',') ?: '0';
    }

    /**
     * Buang karakter penanda Markdown dari teks pengguna.
     *
     * Pengirim pesan memakai parse_mode Markdown, sehingga karakter seperti
     * bintang dan garis bawah bisa merusak tampilan pesan.
     *
     * @param  string $teks
     * @return string
     */
    private function teksAman(string $teks): string
    {
        return str_replace(['*', '_', '`', '[', ']'], '', $teks);
    }
}
