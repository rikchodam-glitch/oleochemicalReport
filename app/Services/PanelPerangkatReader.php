<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * PanelPerangkatReader
 *
 * Pembaca MENTAH perangkat sensor ruang panel. Kelas ini hanya berbicara
 * dengan perangkat di jaringan lalu mengembalikan hasil uraian apa adanya;
 * seluruh keputusan penyimpanan data ada di PanelSensorService.
 *
 * Tiga bentuk layanan perangkat yang didukung:
 *   1. JSON API firmware  : GET http://<ip>:<port>/api/status
 *   2. JSON TCP sketch    : satu baris JSON dari port TCP (bawaan 5000)
 *   3. Dashboard HTML     : halaman http://<ip>:<port>/ memuat "Suhu: xx.xx"
 *
 * Dipisahkan dari PanelSensorService supaya pengujian bisa mengganti pembaca
 * ini dengan tiruan tanpa benar-benar membuka soket ke perangkat.
 */
class PanelPerangkatReader
{
    /**
     * Batas waktu (detik) satu permintaan ke perangkat.
     *
     * @return int
     */
    public function batasDetik(): int
    {
        return max(1, (int) config('panel.pull.timeout_detik', 3));
    }

    /**
     * Panggil satu alamat HTTP yang membalas JSON lalu uraikan hasilnya.
     *
     * @param  string  $url  URL lengkap yang dipanggil.
     * @return array<string, mixed>|null Data hasil urai, atau null bila gagal.
     */
    public function bacaJsonHttp(string $url): ?array
    {
        $batas = $this->batasDetik();

        try {
            $balasan = Http::connectTimeout($batas)->timeout($batas)->get($url);

            if (!$balasan->successful()) {
                return null;
            }

            $data = $balasan->json();
        } catch (\Throwable $galat) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Baca satu baris JSON dari soket TCP perangkat.
     *
     * Sketch dashboard membalas HANYA setelah menerima kiriman byte dari
     * klien (lihat handleTCPClients() pada sketch), jadi satu baris kosong
     * dikirim lebih dulu supaya perangkat mau menjawab.
     *
     * @param  string  $ip  Alamat IP perangkat.
     * @param  int  $port  Port TCP layanan JSON perangkat.
     * @return array<string, mixed>|null Data hasil urai, atau null bila gagal.
     */
    public function bacaJsonTcp(string $ip, int $port): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $port < 1) {
            return null;
        }

        $batas = $this->batasDetik();
        $kodeGalat = 0;
        $pesanGalat = '';

        $soket = @stream_socket_client('tcp://' . $ip . ':' . $port, $kodeGalat, $pesanGalat, $batas);

        if ($soket === false) {
            return null;
        }

        stream_set_timeout($soket, $batas);

        @fwrite($soket, "\n");

        $isi = '';
        $batasWaktu = time() + $batas;

        while (time() <= $batasWaktu) {
            $potongan = @fgets($soket, 512);

            if ($potongan === false || $potongan === '') {
                break;
            }

            $isi .= $potongan;

            // Satu objek JSON utuh sudah cukup; perangkat menutup soket setelah
            // mengirim satu baris sehingga tidak perlu menunggu EOF.
            if (str_contains($isi, '}')) {
                break;
            }
        }

        @fclose($soket);

        return $this->uraikanJson($isi);
    }

    /**
     * Baca dashboard HTML perangkat dan ambil angka suhu/kelembapan darinya.
     *
     * Dipakai sebagai jalan terakhir untuk perangkat sketch, karena halaman
     * dashboard selalu tersedia di port HTTP sementara port TCP JSON bisa saja
     * tertutup oleh aturan jaringan.
     *
     * @param  string  $ip  Alamat IP perangkat.
     * @param  int  $port  Port HTTP dashboard perangkat.
     * @return array<string, mixed>|null Data hasil urai, atau null bila gagal.
     */
    public function bacaDashboardHtml(string $ip, int $port): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $port < 1) {
            return null;
        }

        $batas = $this->batasDetik();

        try {
            $balasan = Http::connectTimeout($batas)->timeout($batas)
                ->get('http://' . $ip . ':' . $port . '/');

            if (!$balasan->successful()) {
                return null;
            }

            $html = $balasan->body();
        } catch (\Throwable $galat) {
            return null;
        }

        $suhu = $this->angkaPertama($html, '/Suhu\s*:\s*(-?\d+(?:[.,]\d+)?)/i');
        $kelembapan = $this->angkaPertama($html, '/Kelembaban\s*:\s*(-?\d+(?:[.,]\d+)?)/i');

        // Halaman yang tidak memuat kedua angka itu bukan dashboard sensor.
        if ($suhu === null && $kelembapan === null) {
            return null;
        }

        return [
            'device'      => 'dashboard-html',
            'ip'          => $ip,
            'temperature' => $suhu,
            'humidity'    => $kelembapan,
            'uptime_sec'  => null,
        ];
    }

    /**
     * Ambil objek JSON pertama yang utuh dari potongan teks.
     *
     * @param  string  $isi  Teks mentah dari perangkat.
     * @return array<string, mixed>|null Data hasil urai, atau null bila tidak ada.
     */
    private function uraikanJson(string $isi): ?array
    {
        $awal = strpos($isi, '{');
        $akhir = strrpos($isi, '}');

        if ($awal === false || $akhir === false || $akhir <= $awal) {
            return null;
        }

        $data = json_decode(substr($isi, $awal, $akhir - $awal + 1), true);

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Ambil angka pertama yang cocok dengan pola pada teks HTML.
     *
     * @param  string  $teks  Isi halaman HTML.
     * @param  string  $pola  Pola reguler dengan satu grup penangkap angka.
     * @return float|null
     */
    private function angkaPertama(string $teks, string $pola): ?float
    {
        if (!preg_match($pola, $teks, $cocok)) {
            return null;
        }

        return (float) str_replace(',', '.', $cocok[1]);
    }
}
