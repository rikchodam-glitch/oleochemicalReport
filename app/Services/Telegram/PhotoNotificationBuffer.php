<?php

namespace App\Services\Telegram;

/**
 * PhotoNotificationBuffer
 *
 * Penahan notifikasi foto untuk Telegram. Telegram mengirim satu kiriman berisi
 * beberapa foto sebagai BEBERAPA update terpisah (semuanya membawa
 * media_group_id yang sama), sehingga tanpa penahan ini teknisi menerima satu
 * pesan balasan per foto ("Foto 1 diterima", "Foto 2 diterima", ...).
 *
 * Cara pakai (di PollTelegramUpdates):
 *   - setiap respons foto yang punya media_group_id ditahan lewat tahan();
 *   - setelah satu batch getUpdates selesai diproses, panggil ambilSemua() dan
 *     kirim hasilnya, sehingga satu kiriman = satu pesan balasan.
 */
class PhotoNotificationBuffer
{
    /**
     * Notifikasi yang ditahan, dikunci per chat + media group.
     *
     * @var array<string, array{chat_id: int|string, message: string, keyboard: array<int, mixed>, jumlah: int}>
     */
    private array $tertahan = [];

    /**
     * Tahan satu respons foto. Respons terakhir untuk grup yang sama dipakai
     * sebagai isi pesan (keyboard-nya sama untuk semua foto pada step yang sama),
     * sementara jumlah fotonya dihitung.
     *
     * @param  int|string  $chatId     ID chat tujuan
     * @param  string      $kunciGrup  Media group ID dari Telegram
     * @param  array       $response   Respons wizard (message/keyboard) atau pesan biasa
     * @return void
     */
    public function tahan(int|string $chatId, string $kunciGrup, array $response): void
    {
        $kunci      = $chatId . '|' . $kunciGrup;
        $sebelumnya = $this->tertahan[$kunci] ?? null;

        $this->tertahan[$kunci] = [
            'chat_id'  => $chatId,
            'message'  => (string) ($response['message'] ?? ''),
            'keyboard' => is_array($response['keyboard'] ?? null) ? $response['keyboard'] : [],
            'jumlah'   => ($sebelumnya['jumlah'] ?? 0) + 1,
        ];
    }

    /**
     * Apakah ada notifikasi yang masih ditahan.
     *
     * @return bool
     */
    public function adaTertahan(): bool
    {
        return $this->tertahan !== [];
    }

    /**
     * Ambil semua notifikasi yang ditahan (satu entri per kiriman foto) lalu
     * kosongkan penahan.
     *
     * @return array<int, array{chat_id: int|string, message: string, keyboard: array<int, mixed>}>
     */
    public function ambilSemua(): array
    {
        $hasil = [];

        foreach ($this->tertahan as $data) {
            $hasil[] = [
                'chat_id'  => $data['chat_id'],
                'message'  => $this->rapikanPesan($data['message'], $data['jumlah']),
                'keyboard' => $data['keyboard'],
            ];
        }

        $this->tertahan = [];

        return $hasil;
    }

    /**
     * Sesuaikan teks pesan dengan jumlah foto dalam satu kiriman:
     * "Foto 3 diterima." menjadi "3 foto diterima." supaya tidak terlihat
     * seperti baru satu foto yang masuk. Pesan lain dikembalikan apa adanya.
     *
     * @param  string  $pesan   Pesan asli dari wizard
     * @param  int     $jumlah  Jumlah foto dalam satu kiriman
     * @return string  Pesan yang sudah disesuaikan
     */
    private function rapikanPesan(string $pesan, int $jumlah): string
    {
        if ($jumlah < 2) {
            return $pesan;
        }

        return (string) preg_replace('/Foto \d+ diterima\./u', $jumlah . ' foto diterima.', $pesan, 1);
    }
}
