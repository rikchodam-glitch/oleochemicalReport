<?php

namespace App\Services;

use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use Illuminate\Support\Carbon;

/**
 * PanelSensorService
 *
 * Pusat logika monitoring ruang panel: berkomunikasi dengan perangkat pembaca
 * sensor suhu/kelembapan, menyimpan hasil pembacaan, dan menyiapkan data untuk
 * halaman dashboard.
 *
 * Dua bentuk perangkat didukung, dipilih lewat kolom
 * `panel_ruangan.protokol`:
 *
 *   1. JSON API firmware resmi (protokol `api-json`)
 *      Perangkat menyimpan buffer pembacaan di RAM sehingga data yang
 *      tertinggal saat server mati tetap terambil:
 *        - GET /api/status          -> identitas perangkat
 *        - GET /api/buffer?since=ID -> tumpukan pembacaan
 *        - GET /api/latest          -> pembacaan terakhir (uji koneksi)
 *
 *   2. Sketch dashboard (protokol `tcp-json` dan `html`)
 *      Perangkat hanya menyajikan NILAI TERKINI: satu baris JSON dari port TCP
 *      (bawaan 5000) dan/atau dashboard HTML di port 80 yang memuat
 *      "Suhu: xx.xx". Tidak ada buffer maupun nomor pembacaan, sehingga waktu
 *      pengukuran memakai waktu penarikan server dan nomor pembacaan dibuat
 *      naik sendiri oleh server.
 *
 * Protokol `otomatis` (nilai bawaan) mencoba ketiganya berurutan: JSON API,
 * lalu JSON TCP, lalu dashboard HTML.
 *
 * Dipilih mode tarik (server yang menyambung ke perangkat) supaya tidak perlu
 * membuka port masuk di firewall komputer ini.
 */
class PanelSensorService
{
    /**
     * Penanda sesi boot untuk perangkat tanpa buffer (sketch dashboard).
     * Nomor pembacaan perangkatnya dibuat naik sendiri oleh server, jadi satu
     * penanda sesi tetap sudah cukup untuk menjaga data dari duplikasi.
     */
    private const SESI_SKETCH = 'sk';

    public function __construct(
        protected ItPingService $pingService,
        protected PanelPerangkatReader $reader
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
        return $this->bacaPerangkat($ruangan)['status'];
    }

    /**
     * Baca perangkat sesuai protokol ruangan ini.
     *
     * Urutan percobaan untuk protokol `otomatis` ditentukan dari yang paling
     * informatif: JSON API firmware (memuat info modul, firmware, dan buffer),
     * lalu JSON TCP sketch, lalu dashboard HTML sketch.
     *
     * @param  PanelRuangan  $ruangan
     * @return array{protokol: string, sumber: string|null, status: array<string, mixed>|null, pesan: string}
     *         `sumber` bernilai json-api, tcp-json, html, atau null bila tidak terjangkau.
     */
    public function bacaPerangkat(PanelRuangan $ruangan): array
    {
        $cara = $this->normalkanProtokol($ruangan);
        $gagal = [];

        if ($cara === 'api-json' || $cara === 'otomatis') {
            $mentah = $this->reader->bacaJsonHttp($ruangan->urlPerangkat() . '/api/status');

            if (is_array($mentah)) {
                // Penanda asal jawaban disisipkan ke dalam status supaya pemroses
                // berikutnya (penyimpanan dan uji koneksi) memakai jalur buffer.
                $mentah['sumber'] = 'json-api';

                return [
                    'protokol' => $cara,
                    'sumber'   => 'json-api',
                    'status'   => $mentah,
                    'pesan'    => 'Perangkat menjawab sebagai JSON API firmware.',
                ];
            }

            if ($cara === 'api-json') {
                return [
                    'protokol' => $cara,
                    'sumber'   => null,
                    'status'   => null,
                    'pesan'    => 'Endpoint JSON API perangkat tidak menjawab.',
                ];
            }

            $gagal[] = 'JSON API tidak menjawab';
        }

        $portTcp = (int) config('panel.sketch.port_tcp', 5000);

        if ($cara === 'tcp-json' || $cara === 'otomatis') {
            $mentah = $this->reader->bacaJsonTcp((string) $ruangan->ip_address, $portTcp);

            if (is_array($mentah)) {
                return [
                    'protokol' => $cara,
                    'sumber'   => 'tcp-json',
                    'status'   => $this->normalkanStatusSketch($mentah, 'tcp-json'),
                    'pesan'    => 'Perangkat menjawab lewat JSON TCP port ' . $portTcp . '.',
                ];
            }

            if ($cara === 'tcp-json') {
                return [
                    'protokol' => $cara,
                    'sumber'   => null,
                    'status'   => null,
                    'pesan'    => 'JSON TCP port ' . $portTcp . ' perangkat tidak menjawab.',
                ];
            }

            $gagal[] = 'JSON TCP port ' . $portTcp . ' tidak menjawab';
        }

        $portHttp = (int) ($ruangan->port ?: 80);

        if ($cara === 'html' || $cara === 'otomatis') {
            $mentah = $this->reader->bacaDashboardHtml((string) $ruangan->ip_address, $portHttp);

            if (is_array($mentah)) {
                return [
                    'protokol' => $cara,
                    'sumber'   => 'html',
                    'status'   => $this->normalkanStatusSketch($mentah, 'html'),
                    'pesan'    => 'Angka dibaca dari dashboard HTML port ' . $portHttp . '.',
                ];
            }

            $gagal[] = 'dashboard HTML port ' . $portHttp . ' tidak menjawab';
        }

        return [
            'protokol' => $cara,
            'sumber'   => null,
            'status'   => null,
            'pesan'    => 'Perangkat tidak bisa dihubungi (' . implode(', ', $gagal) . ').',
        ];
    }


    /**
     * Tarik pembacaan baru dari perangkat ruangan lalu simpan ke database.
     *
     * @param  PanelRuangan  $ruangan
     * @return array{berhasil: bool, baru: int, pesan: string}
     */
    public function tarikPembacaan(PanelRuangan $ruangan): array
    {
        $baca = $this->bacaPerangkat($ruangan);
        $status = $baca['status'];

        if ($status === null) {
            $ruangan->update([
                'status_terakhir'  => 'offline',
                'terakhir_ditarik' => now(),
                'catatan'          => $baca['pesan'] . ' pada ' . now()->format('d/m/Y H:i:s'),
            ]);

            return [
                'berhasil' => false,
                'baru'     => 0,
                'pesan'    => $baca['pesan'],
            ];
        }

        if (($status['sumber'] ?? '') === 'json-api') {
            return $this->tarikDariBuffer($ruangan, $status);
        }

        return $this->tarikDariNilaiTerkini($ruangan, $status);
    }


    /**
     * Tarik pembacaan dari perangkat ber-buffer (JSON API firmware).
     *
     * Bila perangkat memulai sesi boot baru (sesi_boot berbeda), seluruh isi
     * buffer ditarik ulang karena penomoran pembacaan perangkat kembali dari
     * awal. Data tetap aman dari duplikasi karena kunci unik
     * (ruangan, sesi boot, id pembacaan).
     *
     * @param  PanelRuangan  $ruangan
     * @param  array<string, mixed>  $status  Status perangkat hasil bacaPerangkat().
     * @return array{berhasil: bool, baru: int, pesan: string}
     */
    private function tarikDariBuffer(PanelRuangan $ruangan, array $status): array
    {
        $sesiBoot = $this->normalkanSesiBoot($status['boot'] ?? null);
        $sesiTersimpan = $this->normalkanSesiBoot($ruangan->sesi_boot);
        $sejak = $sesiTersimpan === $sesiBoot
            ? (int) ($ruangan->id_pembacaan_terakhir ?? 0)
            : 0;

        $uptimeSekarang = (int) ($status['uptime_s'] ?? 0);
        $buffer = $this->reader->bacaJsonHttp($ruangan->urlPerangkat() . '/api/buffer?since=' . $sejak);
        $daftar = is_array($buffer) ? ($buffer['data'] ?? []) : [];

        if (!is_array($daftar)) {
            $daftar = [];
        }

        // Ambil dari yang paling lama lebih dulu bila tumpukan data melebihi
        // batas per penarikan. Cara ini memastikan tidak ada pembacaan yang
        // terlewat: penarikan berikutnya melanjutkan dari nomor terakhir yang
        // benar-benar sudah tersimpan, bukan lompat ke nomor terbaru.
        $maksimum = max(1, (int) config('panel.maks_pembacaan_per_tarik', 400));

        if (count($daftar) > $maksimum) {
            $daftar = array_slice($daftar, 0, $maksimum);
        }

        $jumlahBaru = 0;
        $idTerproses = $sejak;

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

            $idTerproses = max($idTerproses, $idPerangkat);
        }

        // Yang ditandai "sudah dimiliki server" adalah nomor tertinggi yang
        // benar-benar diproses pada penarikan ini, bukan nomor terbaru di
        // perangkat. Bila tumpukan data lebih banyak daripada batas per
        // penarikan, sisanya tetap terambil pada penarikan berikutnya.
        $ruangan->update([
            'status_terakhir'       => 'online',
            'terakhir_ditarik'      => now(),
            'terakhir_terlihat'     => $jumlahBaru > 0 ? now() : $ruangan->terakhir_terlihat,
            'sesi_boot'             => $sesiBoot,
            'id_pembacaan_terakhir' => $idTerproses,
            'mac_address'           => $ruangan->mac_address ?: ($status['mac'] ?? null),
            'catatan'               => $jumlahBaru > 0 ? null : 'Terhubung, belum ada data baru.',
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
     * Tarik pembacaan dari perangkat tanpa buffer (sketch dashboard).
     *
     * Perangkat hanya menyajikan nilai terkini, jadi waktu pengukuran memakai
     * waktu penarikan server dan nomor pembacaan dibuat naik sendiri. Supaya
     * tabel tidak dipenuhi angka yang sama berulang-ulang, nilai yang tidak
     * berubah hanya disimpan ulang setelah jeda penyimpanan terlewati.
     *
     * @param  PanelRuangan  $ruangan
     * @param  array<string, mixed>  $status  Status hasil normalkanStatusSketch().
     * @return array{berhasil: bool, baru: int, pesan: string}
     */
    private function tarikDariNilaiTerkini(PanelRuangan $ruangan, array $status): array
    {
        $suhu = $status['suhu'] ?? null;
        $kelembapan = $status['kelembapan'] ?? null;
        $sumber = (string) ($status['sumber'] ?? '');

        // Perangkat terhubung tetapi sensornya belum mengirim pembacaan sah
        // (sketch mengirim 0,00 selama sensor belum pernah membaca). Dicatat
        // sebagai online tanpa mengisi data supaya grafik tidak tertipu nol.
        if (!($status['sensor_membaca'] ?? false)) {
            $ruangan->update([
                'status_terakhir'  => 'online',
                'terakhir_ditarik' => now(),
                'sesi_boot'        => self::SESI_SKETCH,
                'catatan'          => 'Perangkat terhubung (' . $sumber . '), tetapi sensor belum mengirim pembacaan (nilai 0). Cek kabel DATA sensor dan pasang resistor pull-up 10k antara VCC dan DATA bila sensornya tipe polos 4 kaki.',
            ]);

            return [
                'berhasil' => true,
                'baru'     => 0,
                'pesan'    => 'Perangkat terhubung, tetapi sensor belum mengirim pembacaan.',
            ];
        }

        $barisTerakhir = PanelPembacaan::query()
            ->where('panel_ruangan_id', $ruangan->id)
            ->orderByDesc('id')
            ->first();

        $jedaSimpan = max(0, (int) config('panel.sketch.jeda_simpan_detik', 60));
        $nilaiBerubah = $this->nilaiBerbeda($barisTerakhir, $suhu, $kelembapan);
        $jedaLewat = $barisTerakhir === null
            || $barisTerakhir->waktu === null
            || $barisTerakhir->waktu->diffInSeconds(now()) >= $jedaSimpan;

        if (!$nilaiBerubah && !$jedaLewat) {
            $ruangan->update([
                'status_terakhir'  => 'online',
                'terakhir_ditarik' => now(),
                'sesi_boot'        => self::SESI_SKETCH,
                'catatan'          => null,
            ]);

            return [
                'berhasil' => true,
                'baru'     => 0,
                'pesan'    => 'Perangkat terhubung, nilai belum berubah.',
            ];
        }

        // Nomor pembacaan naik terus per ruangan. Karena nilainya selalu
        // bertambah, kunci unik (ruangan, sesi, id) tetap aman walau perangkat
        // sempat dimatikan atau protokolnya diganti.
        $idPerangkat = (int) ($ruangan->pembacaan()->max('id_perangkat') ?? 0) + 1;

        $pembacaan = PanelPembacaan::query()->firstOrCreate(
            [
                'panel_ruangan_id' => $ruangan->id,
                'sesi_boot'        => self::SESI_SKETCH,
                'id_perangkat'     => $idPerangkat,
            ],
            [
                'waktu'      => now(),
                'suhu'       => $suhu,
                'kelembapan' => $kelembapan,
                'valid'      => true,
                'sumber'     => 'pull',
            ]
        );

        $ruangan->update([
            'status_terakhir'       => 'online',
            'terakhir_ditarik'      => now(),
            'terakhir_terlihat'     => now(),
            'sesi_boot'             => self::SESI_SKETCH,
            'id_pembacaan_terakhir' => $idPerangkat,
            'catatan'               => null,
        ]);

        return [
            'berhasil' => true,
            'baru'     => $pembacaan->wasRecentlyCreated ? 1 : 0,
            'pesan'    => $pembacaan->wasRecentlyCreated
                ? '1 pembacaan baru tersimpan.'
                : 'Perangkat terhubung, nilai belum berubah.',
        ];
    }


    /**
     * Uji koneksi satu ruangan: ping jaringan, jawaban perangkat, dan
     * pembacaan terakhir yang sedang ada di perangkat.
     *
     * Dipakai tombol "Uji Koneksi" pada halaman panel, supaya bisa dipastikan
     * apakah masalahnya di jaringan, di modul Ethernet, atau di sensor.
     *
     * @param  PanelRuangan  $ruangan
     * @return array{ping: array{status: string, latency_ms: int|null}, status: array<string, mixed>|null, pembacaan: array<string, mixed>|null, sumber: string|null, protokol: string, pesan: string}
     */
    public function ujiKoneksi(PanelRuangan $ruangan): array
    {
        $ping = $this->pingService->ping((string) $ruangan->ip_address);
        $baca = $this->bacaPerangkat($ruangan);
        $status = $baca['status'];

        $pembacaan = null;

        if ($status !== null) {
            if (($status['sumber'] ?? '') === 'json-api') {
                $terakhir = $this->reader->bacaJsonHttp($ruangan->urlPerangkat() . '/api/latest');
                $pembacaan = is_array($terakhir['data'] ?? null) ? $terakhir['data'] : null;
            } else {
                $pembacaan = [
                    'suhu'           => $status['suhu'] ?? null,
                    'kelembapan'     => $status['kelembapan'] ?? null,
                    'sensor_membaca' => (bool) ($status['sensor_membaca'] ?? false),
                ];
            }
        }

        return [
            'ping'      => $ping,
            'status'    => $status,
            'pembacaan' => $pembacaan,
            'sumber'    => $baca['sumber'],
            'protokol'  => $baca['protokol'],
            'pesan'     => $baca['pesan'],
        ];
    }

    /**
     * Tarik data dari seluruh ruangan panel yang aktif.
     *
     * Ruangan yang sedang tidak bisa dihubungi akan dilewati sementara sesuai
     * jeda di config panel.pull.jeda_gagal_detik, supaya halaman panel tidak
     * menunggu batas waktu berulang kali untuk tiap ruangan yang offline.
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
     * panel ikut menunggu batas waktu berulang kali.
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
     * Susun status seragam dari perangkat tanpa buffer (sketch dashboard).
     *
     * @param  array<string, mixed>  $mentah  Jawaban perangkat apa adanya.
     * @param  string  $sumber  Asal jawaban: tcp-json atau html.
     * @return array<string, mixed>
     */
    private function normalkanStatusSketch(array $mentah, string $sumber): array
    {
        $suhu = $this->keAngka($mentah['temperature'] ?? $mentah['suhu'] ?? null);
        $kelembapan = $this->keAngka($mentah['humidity'] ?? $mentah['kelembapan'] ?? null);
        $uptime = $mentah['uptime_sec'] ?? null;

        return [
            'sumber'           => $sumber,
            'device'           => (string) ($mentah['device'] ?? ''),
            'ip'               => (string) ($mentah['ip'] ?? ''),
            'mac'              => $mentah['mac'] ?? null,
            'uptime_s'         => is_numeric($uptime) ? (int) $uptime : null,
            'boot'             => null,
            'w5500_terdeteksi' => null,
            'suhu'             => $suhu,
            'kelembapan'       => $kelembapan,
            'sensor_membaca'   => $this->sensorMembaca($suhu, $kelembapan),
            'mentah'           => $mentah,
        ];
    }


    /**
     * Apakah nilai dari perangkat tanpa buffer benar-benar hasil pembacaan.
     *
     * Sketch dashboard mengirim 0,00/0,00 selama sensor belum pernah berhasil
     * membaca (variabelnya diawali nol dan hanya ditimpa saat pembacaan sukses).
     * Dua nilai nol sekaligus tidak mungkin pada suhu ruang, jadi pasangan itu
     * dianggap sebagai sensor belum membaca.
     *
     * @param  float|null  $suhu
     * @param  float|null  $kelembapan
     * @return bool
     */
    private function sensorMembaca(?float $suhu, ?float $kelembapan): bool
    {
        if ($suhu === null && $kelembapan === null) {
            return false;
        }

        return (float) ($suhu ?? 0) !== 0.0 || (float) ($kelembapan ?? 0) !== 0.0;
    }

    /**
     * Apakah nilai baru berbeda dengan baris pembacaan terakhir.
     *
     * @param  PanelPembacaan|null  $baris  Baris terakhir yang tersimpan.
     * @param  float|null  $suhu
     * @param  float|null  $kelembapan
     * @return bool
     */
    private function nilaiBerbeda(?PanelPembacaan $baris, ?float $suhu, ?float $kelembapan): bool
    {
        if ($baris === null) {
            return true;
        }

        $suhuLama = $baris->suhu === null ? null : round((float) $baris->suhu, 2);
        $kelembapanLama = $baris->kelembapan === null ? null : round((float) $baris->kelembapan, 2);

        return $suhuLama !== $suhu || $kelembapanLama !== $kelembapan;
    }

    /**
     * Normalkan protokol ruangan menjadi salah satu nilai yang dikenal.
     *
     * @param  PanelRuangan  $ruangan
     * @return string otomatis, api-json, tcp-json, atau html.
     */
    private function normalkanProtokol(PanelRuangan $ruangan): string
    {
        $cara = (string) ($ruangan->protokol ?? '');

        return array_key_exists($cara, PanelRuangan::PROTOKOL) ? $cara : 'otomatis';
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
