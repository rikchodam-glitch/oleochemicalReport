<?php

namespace App\Services;

use App\Models\PanelNotifikasiTerkirim;
use App\Models\Technician;
use App\Services\Shift\JamKerjaService;
use App\Services\Shift\ShiftPatternService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * PanelNotifikasiService
 *
 * Mengirim pengingat harian kondisi ruang panel lewat Telegram kepada tiap
 * PENANGGUNG JAWAB (PIC) ruangan, tepat pada awal jam masuk kerja orang itu:
 *
 *   - teknisi non-shift (reguler / reguler staff) memakai jam kerja hariannya
 *     di config('cm.man_hour.jam_kerja') — hari libur tidak dikirimi pesan;
 *   - pekerja shift (Grub A-D) memakai kode shift hasil proyeksi pola rotasi
 *     (ShiftPatternService) — hari OFF ('x') tidak dikirimi pesan.
 *
 * Pengiriman dijalankan perintah `panel:kirim-pengingat` tiap menit, lalu jam
 * masuk kerja dibandingkan dengan waktu sekarang. Satu teknisi hanya menerima
 * satu pesan per hari: catatannya ada di tabel panel_notifikasi_terkirim
 * (tanpa catatan itu, pesan akan terkirim berkali-kali sepanjang jendela waktu).
 */
class PanelNotifikasiService
{
    public function __construct(
        protected TelegramService $telegram,
        protected JamKerjaService $jamKerja,
        protected ShiftPatternService $polaShift,
        protected PanelRiwayatService $riwayat
    ) {
    }

    /**
     * Jam mulai kerja satu teknisi pada satu tanggal.
     *
     * @param  Technician       $teknisi  Teknisi yang diperiksa
     * @param  CarbonInterface  $tanggal  Tanggal yang dipakai
     * @return string|null  Jam "HH:MM", atau null bila teknisi libur/OFF
     */
    public function mulaiKerja(Technician $teknisi, CarbonInterface $tanggal): ?string
    {
        $grup = (string) ($teknisi->group ?? 'reguler');

        if (Technician::grupShift($grup)) {
            $kode = $this->polaShift->kode($grup, $tanggal);

            if ($kode === null || strtolower($kode) === 'x') {
                return null;
            }

            $windows = (array) config('cm.man_hour.windows', []);

            return isset($windows[$kode]['mulai']) ? (string) $windows[$kode]['mulai'] : null;
        }

        $jadwal = $this->jamKerja->hari($grup, $tanggal);

        return $jadwal === null ? null : (string) $jadwal['mulai'];
    }

    /**
     * Kirim pengingat kepada seluruh penanggung jawab yang jam masuk kerjanya
     * sedang berjalan pada waktu yang diberikan (dipakai penjadwal tiap menit).
     *
     * @param  CarbonInterface|null  $sekarang  Waktu acuan; null berarti waktu sekarang
     * @return array<string, mixed> Ringkasan pengiriman
     */
    public function kirimOtomatis(?CarbonInterface $sekarang = null): array
    {
        $waktu   = $sekarang ?? now();
        $jendela = max(1, (int) config('panel.notifikasi.jendela_menit', 15));

        return $this->kirim(
            $waktu,
            fn (Technician $teknisi, ?string $mulai): bool => $mulai !== null
                && $this->sedangJendelaMasuk($waktu, $mulai, $jendela)
                && ! $this->sudahDikirimHariIni($teknisi, $waktu),
            true,
            true
        );
    }

    /**
     * Kirim pengingat tanpa memeriksa jam kerja dan tanpa catatan harian
     * (dipakai tombol/perintah uji kirim oleh administrator).
     *
     * @param  Technician|null       $teknisi   Bila diisi, hanya teknisi ini yang dikirimi
     * @param  CarbonInterface|null  $sekarang  Waktu acuan; null berarti waktu sekarang
     * @return array<string, mixed> Ringkasan pengiriman
     */
    public function kirimPaksa(?Technician $teknisi = null, ?CarbonInterface $sekarang = null): array
    {
        $waktu = $sekarang ?? now();

        return $this->kirim(
            $waktu,
            fn (Technician $calon, ?string $mulai): bool => $teknisi === null || $calon->id === $teknisi->id,
            false,
            false
        );
    }

    /**
     * Kirim pengingat hanya kepada penanggung jawab tertentu tanpa memeriksa
     * jam kerja (dipakai tombol "Kirim Pengingat" pada halaman detail ruangan).
     *
     * @param  array<int, int>       $idTeknisi  Daftar ID teknisi penerima
     * @param  CarbonInterface|null  $sekarang   Waktu acuan; null berarti waktu sekarang
     * @return array<string, mixed> Ringkasan pengiriman
     */
    public function kirimKeTeknisi(array $idTeknisi, ?CarbonInterface $sekarang = null): array
    {
        $waktu = $sekarang ?? now();

        return $this->kirim(
            $waktu,
            fn (Technician $calon, ?string $mulai): bool => true,
            false,
            false,
            array_map('intval', $idTeknisi)
        );
    }

    /**
     * Susun pesan pengingat kondisi ruangan milik satu teknisi.
     *
     * Format pesan (satu blok per ruangan):
     *
     *   Halo <nama>, kondisi terkini <ruangan> (<lokasi>)
     *   Suhu Rata-rata: xx,xx C / Kelembapan: xx,xx %        (tebal)
     *   Suhu Terkini: xx,xx C / Kelembapan: xx,xx %
     *   Kondisi: TERLALU PANAS (berdasarkan suhu rata-rata)  (tebal)
     *   Perangkat: offline, pembacaan terakhir dd/mm/yyyy HH:ii
     *
     * Sapaan hanya ditulis pada blok PERTAMA supaya pesan dengan beberapa ruangan
     * tetap enak dibaca. Angka "Suhu Rata-rata" beserta lencana kondisi dihitung
     * dari pembacaan `config('panel.notifikasi.rata_rata_jam')` jam terakhir
     * (bawaan 24 jam), sedangkan "Suhu Terkini" tetap pembacaan paling baru.
     * Nama ruangan sekaligus menjadi tautan ke halaman detail ruangan, dan pesan
     * ditutup satu baris tautan dashboard.
     *
     * @param  Technician            $teknisi   Penanggung jawab penerima pesan
     * @param  CarbonInterface|null  $sekarang  Waktu acuan; null berarti waktu sekarang
     * @return array{pesan: string, jumlah: int} Pesan siap kirim dan jumlah ruangan
     */
    public function susunPesan(Technician $teknisi, ?CarbonInterface $sekarang = null): array
    {
        $ruangan = $teknisi->panelYangDitangani()
            ->with('pembacaanTerakhir')
            ->orderBy('nama_ruangan')
            ->get();

        if ($ruangan->isEmpty()) {
            return ['pesan' => '', 'jumlah' => 0];
        }

        $waktu       = $sekarang ?? now();
        $alamatDasar = rtrim((string) config('app.url'), '/');
        $sejak       = $waktu->copy()->subHours(max(1, (int) config('panel.notifikasi.rata_rata_jam', 24)));

        $baris = [];

        foreach ($ruangan as $nomor => $satu) {
            $terakhir = $satu->pembacaanTerakhir;
            $ringkas  = $this->riwayat->ringkasRentang($satu, $sejak, $waktu);

            // Kondisi dinilai dari SUHU RATA-RATA (bukan pembacaan terakhir) agar
            // lonjakan sesaat tidak langsung dilaporkan sebagai terlalu panas.
            $labelKondisi = match ($satu->kondisiSuhu($ringkas['suhu'])) {
                'tinggi' => 'TERLALU PANAS',
                'rendah' => 'TERLALU DINGIN',
                'normal' => 'SUHU NORMAL',
                default  => 'BELUM ADA DATA',
            };

            // Nama ruangan sekaligus menjadi tautan ke halaman detailnya.
            $tautanRuangan = $alamatDasar . '/panel/ruangan/' . $satu->id;
            $namaRuangan   = '<a href="' . $this->aman($tautanRuangan) . '">'
                . $this->aman($satu->nama_ruangan) . ' (' . $this->aman($satu->labelLokasi()) . ')</a>';

            $sapaan = $nomor === 0
                ? 'Halo ' . $this->aman($teknisi->name) . ', kondisi terkini '
                : 'Kondisi terkini ';

            $baris[] = $sapaan . $namaRuangan;
            $baris[] = '<b>Suhu Rata-rata: ' . $this->angka($ringkas['suhu'], ' C')
                . ' / Kelembapan: ' . $this->angka($ringkas['kelembapan'], ' %') . '</b>';
            $baris[] = 'Suhu Terkini: ' . $this->angka($terakhir?->suhu, ' C')
                . ' / Kelembapan: ' . $this->angka($terakhir?->kelembapan, ' %');
            $baris[] = '<b>Kondisi: ' . $labelKondisi . ' (berdasarkan suhu rata-rata)</b>';
            $baris[] = 'Perangkat: ' . ($satu->apakahOnline() ? 'online' : 'offline')
                . ', pembacaan terakhir '
                . ($terakhir?->waktu?->format('d/m/Y H:i') ?? 'belum ada');
            $baris[] = '';
        }

        $baris[] = 'Dashboard lengkap: ' . $alamatDasar . '/panel';

        return ['pesan' => implode("\n", $baris), 'jumlah' => $ruangan->count()];
    }

    /**
     * Apakah teknisi ini sudah dikirimi pengingat pada tanggal waktu acuan.
     *
     * @param  Technician       $teknisi
     * @param  CarbonInterface  $waktu
     * @return bool
     */
    public function sudahDikirimHariIni(Technician $teknisi, CarbonInterface $waktu): bool
    {
        return PanelNotifikasiTerkirim::query()
            ->where('technician_id', $teknisi->id)
            ->whereDate('tanggal', $waktu->toDateString())
            ->exists();
    }


    /**
     * Apakah waktu sekarang berada di dalam jendela awal jam masuk kerja.
     *
     * Jendela (bawaan 15 menit) dipakai supaya pengingat tetap terkirim bila
     * penjadwal sempat mati beberapa menit pada menit yang tepat.
     *
     * @param  CarbonInterface  $sekarang    Waktu sekarang
     * @param  string           $mulai       Jam masuk kerja "HH:MM"
     * @param  int              $jendela     Panjang jendela dalam menit
     * @return bool
     */
    private function sedangJendelaMasuk(CarbonInterface $sekarang, string $mulai, int $jendela): bool
    {
        $menitSekarang = ((int) $sekarang->format('H')) * 60 + ((int) $sekarang->format('i'));

        $potongan   = explode(':', $mulai);
        $menitMulai = ((int) ($potongan[0] ?? 0)) * 60 + ((int) ($potongan[1] ?? 0));

        return $menitSekarang >= $menitMulai && $menitSekarang <= ($menitMulai + $jendela);
    }

    /**
     * Jalankan pengiriman untuk seluruh penanggung jawab ruang panel.
     *
     * @param  CarbonInterface  $waktu        Waktu acuan pengiriman
     * @param  callable         $syaratKirim  Penentu apakah seorang teknisi dikirimi
     * @param  bool             $catat        True bila pengiriman dicatat (sekali per hari)
     * @param  bool             $wajibJadwal  True bila teknisi tanpa jadwal kerja (libur/OFF) dilewati
     * @param  array<int, int>  $idTerpilih   Bila diisi, hanya teknisi dengan ID ini yang dikirimi
     * @return array<string, mixed> Ringkasan: diperiksa, terkirim, gagal, dilewati, detail
     */
    private function kirim(
        CarbonInterface $waktu,
        callable $syaratKirim,
        bool $catat = true,
        bool $wajibJadwal = true,
        array $idTerpilih = []
    ): array {
        $calon = Technician::query()
            ->whereHas('panelYangDitangani')
            ->orderBy('name')
            ->get();

        $ringkasan = [
            'waktu'     => $waktu->format('d/m/Y H:i'),
            'diperiksa' => $calon->count(),
            'terkirim'  => 0,
            'gagal'     => 0,
            'dilewati'  => 0,
            'detail'    => [],
        ];

        foreach ($calon as $teknisi) {
            // Teknisi di luar daftar terpilih tidak dihitung sebagai "dilewati":
            // ringkasannya hanya menggambarkan penerima yang diminta.
            if ($idTerpilih !== [] && ! in_array((int) $teknisi->id, $idTerpilih, true)) {
                continue;
            }

            $mulai = $this->mulaiKerja($teknisi, $waktu);

            // Hari libur / OFF: tidak ada awal jam masuk kerja, jadi tidak ada
            // pengingat. Pada mode uji kirim pemeriksaan ini sengaja dilewati.
            if ($wajibJadwal && $mulai === null) {
                $ringkasan['dilewati']++;
                continue;
            }

            if (! $syaratKirim($teknisi, $mulai)) {
                $ringkasan['dilewati']++;
                continue;
            }

            if ($teknisi->telegram_id === null || $teknisi->telegram_id === '') {
                $ringkasan['dilewati']++;
                continue;
            }

            $pesan = $this->susunPesan($teknisi, $waktu);

            if ($pesan['jumlah'] === 0) {
                $ringkasan['dilewati']++;
                continue;
            }

            $terkirim = $this->telegram->sendMessage($teknisi->telegram_id, $pesan['pesan']);

            $ringkasan['detail'][] = [
                'teknisi'   => $teknisi->name,
                'jam_kerja' => $mulai ?? '-',
                'ruangan'   => $pesan['jumlah'],
                'status'    => $terkirim ? 'terkirim' : 'gagal',
            ];

            if (! $terkirim) {
                $ringkasan['gagal']++;
                continue;
            }

            $ringkasan['terkirim']++;

            if ($catat) {
                PanelNotifikasiTerkirim::query()->updateOrCreate(
                    [
                        'technician_id' => $teknisi->id,
                        'tanggal'       => $waktu->toDateString(),
                    ],
                    [
                        'jam_kerja'      => $mulai,
                        'jumlah_ruangan' => $pesan['jumlah'],
                    ]
                );
            }
        }

        if ($ringkasan['terkirim'] > 0) {
            Log::info('PanelNotifikasi: pengingat ruang panel terkirim kepada '
                . $ringkasan['terkirim'] . ' penanggung jawab pada ' . $ringkasan['waktu'] . '.');
        }

        return $ringkasan;
    }

    /**
     * Amankan teks dinamis sebelum dikirim (pesan Telegram memakai mode HTML).
     *
     * @param  string|null  $teks
     * @return string
     */
    private function aman(?string $teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Tampilkan angka pengukuran: dua desimal dengan pemisah koma, atau "-".
     *
     * @param  float|null  $nilai
     * @param  string      $satuan
     * @return string
     */
    private function angka(?float $nilai, string $satuan): string
    {
        return $nilai === null ? '-' : number_format($nilai, 2, ',', '.') . $satuan;
    }
}

