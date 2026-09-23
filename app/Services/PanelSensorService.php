<?php

namespace App\Services;

use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * PanelSensorService
 *
 * Pusat logika monitoring ruang panel: berkomunikasi dengan perangkat ESP32
 * pembaca sensor suhu/kelembapan, menyimpan hasil pembacaan, dan menyiapkan
 * data untuk halaman dashboard.
 *
 * Cara kerja yang dipakai (mode tarik / pull):
 *   1. Server menanyakan status perangkat (GET /api/status).
 *   2. Server menarik pembacaan yang belum dimiliki (GET /api/buffer?since=ID).
 *   3. Hasil disimpan ke tabel panel_pembacaan tanpa data ganda.
 *
 * Dipilih mode tarik supaya tidak perlu membuka port masuk di firewall
 * komputer server. Perangkat tetap menyimpan buffer di RAM, sehingga data
 * yang tertinggal saat server mati tetap terambil setelah server hidup lagi.
 */
class PanelSensorService
{
    public function __construct(
        protected ItPingService $pingService
    ) {
    }

    /**
     * Ambil status perangkat sensor pada satu ruangan.
     *
     * @param  PanelRuangan  $ruangan
     * @return array<string, mixed>|null Data status, atau null bila tidak terhubung.
     */
    public function ambilStatus(PanelRuangan $ruangan): ?array
    {
        return $this->panggilJson($ruangan->urlPerangkat() . '/api/status');
    }

    /**
     * Tarik pembacaan baru dari perangkat ruangan lalu simpan ke database.
     *
     * Bila perangkat memulai sesi boot baru (sesi_boot berbeda), seluruh isi
     * buffer ditarik ulang karena penomoran pembacaan perangkat kembali dari
     * awal. Data tetap aman dari duplikasi karena kunci unik
     * (ruangan, sesi boot, id pembacaan).
     *
     * @param  PanelRuangan  $ruangan
     * @return array{berhasil: bool, baru: int, pesan: string}
     */
    public function tarikPembacaan(PanelRuangan $ruangan): array
    {
        $status = $this->ambilStatus($ruangan);

        if ($status === null) {
            $ruangan->update([
                'status_terakhir'  => 'offline',
                'terakhir_ditarik' => now(),
                'catatan'          => 'Tidak bisa dihubungi pada ' . now()->format('d/m/Y H:i:s'),
            ]);

            return [
                'berhasil' => false,
                'baru'     => 0,
                'pesan'    => 'Perangkat tidak bisa dihubungi.',
            ];
        }

        $sesiBoot = $this->normalkanSesiBoot($status['boot'] ?? null);
        $sesiTersimpan = $this->normalkanSesiBoot($ruangan->sesi_boot);
        $sejak = $sesiTersimpan === $sesiBoot
            ? (int) ($ruangan->id_pembacaan_terakhir ?? 0)
            : 0;

        $uptimeSekarang = (int) ($status['uptime_s'] ?? 0);
        $buffer = $this->panggilJson($ruangan->urlPerangkat() . '/api/buffer?since=' . $sejak);
        $daftar = is_array($buffer) ? ($buffer['data'] ?? []) : [];

        if (!is_array($daftar)) {
            $daftar = [];
        }

        $maksimum = max(1, (int) config('panel.maks_pembacaan_per_tarik', 120));
        if (count($daftar) > $maksimum) {
            $daftar = array_slice($daftar, -$maksimum);
        }

        $jumlahBaru = 0;

        foreach ($daftar as $item) {
            if (!is_array($item)) {
                continue;
            }

            $idPerangkat = (int) ($item['id'] ?? 0);
            $suhu = $this->keAngka($item['suhu'] ?? null);
            $kelembapan = $this->keAngka($item['kelembapan'] ?? null);

            // Lewati baris yang tidak berisi angka apa pun (misalnya heartbeat).
            if ($idPerangkat <= 0 || ($suhu === null && $kelembapan === null)) {
                continue;
            }

            $pembacaan = PanelPembacaan::query()->firstOrCreate(
                [
                    'panel_ruangan_id' => $ruangan->id,
                    'sesi_boot'        => $sesiBoot,
                    'id_perangkat'     => $idPerangkat,
                ],
                [
                    'waktu'      => $this->waktuPengukuran($uptimeSekarang, (int) ($item['uptime_s'] ?? 0)),
                    'suhu'       => $suhu,
                    'kelembapan' => $kelembapan,
                    'valid'      => (bool) ($item['valid'] ?? true),
                    'sumber'     => 'pull',
                ]
            );

            if ($pembacaan->wasRecentlyCreated) {
                $jumlahBaru++;
            }
        }

        $idTerakhirPerangkat = max($sejak, (int) ($status['id_terakhir'] ?? $sejak));

        $ruangan->update([
            'status_terakhir'        => 'online',
            'terakhir_ditarik'       => now(),
            'terakhir_terlihat'      => $jumlahBaru > 0 ? now() : $ruangan->terakhir_terlihat,
            'sesi_boot'              => $sesiBoot,
            'id_pembacaan_terakhir'  => $idTerakhirPerangkat,
            'mac_address'            => $ruangan->mac_address ?: ($status['mac'] ?? null),
            'catatan'                => $jumlahBaru > 0 ? null : 'Terhubung, belum ada data baru.',
        ]);

        return [
            'berhasil' => true,
            'baru'     => $jumlahBaru,
            'pesan'    => $jumlahBaru > 0
                ? sprintf('%d pembacaan baru tersimpan.', $jumlahBaru)
                : 'Perangkat terhubung, belum ada pembacaan baru.',
        ];
    }

    /**
     * Uji koneksi satu ruangan: ping jaringan, status perangkat, dan
     * pembacaan terakhir yang sedang ada di perangkat.
     *
     * Dipakai tombol "Uji Koneksi" pada halaman panel, supaya bisa dipastikan
     * apakah masalahnya di jaringan, di modul Ethernet, atau di sensor.
     *
     * @param  PanelRuangan  $ruangan
     * @return array{ping: array{status: string, latency_ms: int|null}, status: array<string, mixed>|null, pembacaan: array<string, mixed>|null}
     */
    public function ujiKoneksi(PanelRuangan $ruangan): array
    {
        $ping = $this->pingService->ping((string) $ruangan->ip_address);
        $status = $this->ambilStatus($ruangan);

        $pembacaan = null;
        if ($status !== null) {
            $terakhir = $this->panggilJson($ruangan->urlPerangkat() . '/api/latest');
            $pembacaan = is_array($terakhir['data'] ?? null) ? $terakhir['data'] : null;
        }

        return [
            'ping'      => $ping,
            'status'    => $status,
            'pembacaan' => $pembacaan,
        ];
    }

    /**
     * Tarik data dari seluruh ruangan panel yang aktif.
     *
     * Ruangan yang sedang tidak bisa dihubungi akan dilewati sementara sesuai
     * jeda di config panel.pull.jeda_gagal_detik, supaya halaman panel tidak
     * menunggu batas waktu HTTP berulang kali untuk tiap ruangan yang offline.
     *
     * @return array{ruangan: int, berhasil: int, dilewati: int, baru: int, detail: array<int, array<string, mixed>>}
     */
    public function tarikSemua(): array
    {
        $ringkasan = [
            'ruangan'  => 0,
            'berhasil' => 0,
            'dilewati' => 0,
            'baru'     => 0,
            'detail'   => [],
        ];

        $jedaGagal = max(5, (int) config('panel.pull.jeda_gagal_detik', 30));
        $daftar = PanelRuangan::query()->aktif()->orderBy('nama_ruangan')->get();

        foreach ($daftar as $ruangan) {
            if (!$this->perluDitarik($ruangan, $jedaGagal)) {
                $ringkasan['dilewati']++;

                continue;
            }

            $hasil = $this->tarikPembacaan($ruangan);

            $ringkasan['ruangan']++;
            $ringkasan['baru'] += $hasil['baru'];

            if ($hasil['berhasil']) {
                $ringkasan['berhasil']++;
            }

            $ringkasan['detail'][] = [
                'nama'  => $ruangan->nama_ruangan,
                'pesan' => $hasil['pesan'],
                'baru'  => $hasil['baru'],
            ];
        }

        return $ringkasan;
    }

    /**
     * Siapkan data tampilan satu ruangan: nilai terkini, titik grafik, dan
     * riwayat pembacaan terakhir.
     *
     * @param  PanelRuangan  $ruangan
     * @param  int  $jumlahTitik  Jumlah titik data untuk grafik.
     * @param  int  $jumlahBaris  Jumlah baris riwayat untuk tabel.
     * @return array{terakhir: PanelPembacaan|null, grafik: array<int, array<string, mixed>>, riwayat: array<int, array<string, mixed>>}
     */
    public function dataRuangan(PanelRuangan $ruangan, int $jumlahTitik, int $jumlahBaris): array
    {
        $terakhir = $ruangan->pembacaan()->orderByDesc('waktu')->orderByDesc('id')->first();

        $grafik = $ruangan->pembacaan()
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->limit(max(1, $jumlahTitik))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (PanelPembacaan $baris): array => [
                'waktu'       => $baris->waktu?->format('H:i:s'),
                'waktu_penuh' => $baris->waktu?->format('d/m/Y H:i:s'),
                'suhu'        => $baris->suhu,
                'kelembapan'  => $baris->kelembapan,
            ])
            ->all();

        $riwayat = $ruangan->pembacaan()
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->limit(max(1, $jumlahBaris))
            ->get()
            ->map(fn (PanelPembacaan $baris): array => [
                'waktu'      => $baris->waktu?->format('d/m/Y H:i:s'),
                'suhu'       => $baris->suhu,
                'kelembapan' => $baris->kelembapan,
                'valid'      => $baris->valid,
            ])
            ->all();

        return [
            'terakhir' => $terakhir,
            'grafik'   => $grafik,
            'riwayat'  => $riwayat,
        ];
    }

    /**
     * Tentukan apakah satu ruangan perlu dicoba ditarik saat ini.
     *
     * Ruangan yang terakhir tercatat offline akan dilewati sampai jeda
     * percobaan terlewati, supaya percobaan berulang tidak membuat halaman
     * panel ikut menunggu batas waktu HTTP.
     *
     * @param  PanelRuangan  $ruangan
     * @param  int  $jedaGagalDetik  Jeda antar percobaan untuk perangkat offline.
     * @return bool
     */
    private function perluDitarik(PanelRuangan $ruangan, int $jedaGagalDetik): bool
    {
        if ($ruangan->status_terakhir !== 'offline') {
            return true;
        }

        $percobaanTerakhir = $ruangan->terakhir_ditarik;

        if ($percobaanTerakhir === null) {
            return true;
        }

        return $percobaanTerakhir->diffInSeconds(now()) >= $jedaGagalDetik;
    }

    /**
     * Panggil endpoint JSON pada perangkat dan uraikan hasilnya.
     *
     * @param  string  $url  URL lengkap yang dipanggil.
     * @return array<string, mixed>|null Data hasil urai, atau null bila gagal.
     */
    private function panggilJson(string $url): ?array
    {
        $batasDetik = max(1, (int) config('panel.pull.timeout_detik', 3));

        try {
            $balasan = Http::connectTimeout($batasDetik)->timeout($batasDetik)->get($url);

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
     * Ubah nilai dari JSON perangkat menjadi angka, atau null bila kosong.
     *
     * @param  mixed  $nilai
     * @return float|null
     */
    private function keAngka(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '' || !is_numeric($nilai)) {
            return null;
        }

        return round((float) $nilai, 2);
    }

    /**
     * Normalkan penanda sesi boot perangkat supaya aman disimpan (huruf dan
     * angka saja, maksimal 12 karakter).
     *
     * @param  mixed  $sesi
     * @return string
     */
    private function normalkanSesiBoot(mixed $sesi): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9]/', '', (string) $sesi);

        if ($bersih === null || $bersih === '') {
            return 'tanpa-sesi';
        }

        return substr($bersih, 0, 12);
    }

    /**
     * Hitung waktu pengukuran dari selisih uptime perangkat.
     *
     * Perangkat tidak punya jam sendiri, jadi waktu pengukuran mundur sebesar
     * selisih uptime perangkat saat ini dengan uptime saat data diukur.
     *
     * @param  int  $uptimeSekarang  Uptime perangkat saat data ditarik (detik).
     * @param  int  $uptimeData      Uptime perangkat saat data diukur (detik).
     * @return Carbon
     */
    private function waktuPengukuran(int $uptimeSekarang, int $uptimeData): Carbon
    {
        $selisihDetik = max(0, $uptimeSekarang - $uptimeData);

        return now()->subSeconds($selisihDetik);
    }
}
