<?php

namespace App\Services\Shift;

use App\Models\Report;
use App\Models\Technician;
use App\Models\TechnicianShift;
use Carbon\CarbonInterface;
/**
 * Perhitungan man hour teknisi: jam kerja terealisasi (dari durasi laporan)
 * dibandingkan target 8 jam sesuai jadwal shift hasil import.
 *
 * Aturan yang dipakai:
 * - Hari dengan kode 'x' (OFF) tidak punya target -> otomatis "libur".
 * - Hari shift 1/2/3 dan hari reguler punya target 480 menit (config).
 * - Hari tanpa baris jadwal dianggap hari kerja reguler (target 480 menit),
 *   kecuali Minggu bila config minggu_libur_reguler aktif.
 * - Baris salinan pekerjaan multi alat (multi_of) TIDAK dihitung agar satu
 *   pekerjaan tidak terhitung berkali-kali; baris kolaborasi dihitung karena
 *   baris itu memang milik teknisi kolaborator.
 */
class ManHourService
{
    /**
     * Rincian man hour per hari untuk satu teknisi pada rentang tanggal.
     *
     * @param  Technician      $technician Teknisi yang dihitung
     * @param  CarbonInterface $mulai      Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai    Tanggal akhir (inklusif)
     * @return array<int, array<string, mixed>> Rincian per hari (urut tanggal)
     */
    public function harian(Technician $technician, CarbonInterface $mulai, CarbonInterface $selesai): array
    {
        $awal  = $mulai->copy()->startOfDay();
        $akhir = $selesai->copy()->startOfDay();

        $laporan = Report::query()
            ->where('technician_id', $technician->id)
            ->whereNull('multi_of')
            ->whereBetween('report_date', [$awal->toDateString() . ' 00:00:00', $akhir->toDateString() . ' 23:59:59'])
            ->orderBy('report_date')
            ->get(['id', 'report_code', 'report_date', 'work_duration_minutes', 'start_time', 'end_time', 'status', 'work_description']);

        $perHari = [];

        foreach ($laporan as $baris) {
            $tanggal = $baris->report_date?->toDateString();

            if ($tanggal === null) {
                continue;
            }

            $perHari[$tanggal][] = $baris;
        }

        $jadwal = TechnicianShift::query()
            ->where('technician_id', $technician->id)
            ->antara($awal->toDateString(), $akhir->toDateString())
            ->get()
            ->keyBy(fn (TechnicianShift $shift): string => $shift->shift_date->toDateString());

        $grup = (string) ($technician->group ?? 'reguler');

        // Tanggal yang belum ada di jadwal import diisi dari pola rotasi 20 hari
        // supaya jadwal bulan berikutnya tidak perlu diupload lagi. Jadwal import
        // selalu menang bila ada (bisa dipakai untuk koreksi manual).
        foreach (app(ShiftPatternService::class)->proyeksi($grup, $awal, $akhir) as $tanggalPola => $kodePola) {
            if (isset($jadwal[$tanggalPola])) {
                continue;
            }

            $jadwal[$tanggalPola] = new TechnicianShift([
                'technician_id' => $technician->id,
                'shift_date'    => $tanggalPola,
                'shift_code'    => $kodePola,
            ]);
        }

        $rincian = [];

        for ($tanggal = $awal->copy(); $tanggal->lte($akhir); $tanggal->addDay()) {
            $kunci       = $tanggal->toDateString();
            $shift       = $jadwal[$kunci] ?? null;
            $laporanHari = $perHari[$kunci] ?? [];
            $menit       = 0;
            $catatan     = [];

            foreach ($laporanHari as $baris) {
                $menit += (int) ($baris->work_duration_minutes ?? 0);
            }

            $target      = $this->targetHari($shift, $tanggal, $grup);
            $jumlahKerja = count($laporanHari);

            if ($shift !== null && $shift->isOff() && $jumlahKerja > 0) {
                $catatan[] = 'Ada ' . $jumlahKerja . ' pekerjaan pada hari OFF';
            }

            if ($shift !== null && !$shift->isOff() && $jumlahKerja > 0) {
                $luar = $this->hitungLaporanLuarShift($laporanHari, $shift, $grup);

                if ($luar > 0) {
                    $catatan[] = $luar . ' laporan di luar jam ' . $shift->label($grup);
                }
            }

            $rincian[] = [
                'tanggal'          => $kunci,
                'hari'             => $tanggal->locale('id')->translatedFormat('l'),
                'kode'             => $shift?->kode(),
                'label_shift'      => $shift?->label($grup) ?? app(JamKerjaService::class)->label($grup, $tanggal),
                'target_menit'     => $target,
                'menit'            => $menit,
                'persen'           => $target > 0 ? (int) round($menit / $target * 100) : null,
                'jumlah_pekerjaan' => $jumlahKerja,
                'status'           => $this->statusHari($target, $menit),
                'catatan'          => $catatan,
                'laporan'          => $laporanHari,
            ];
        }

        return $rincian;
    }

    /**
     * Ringkasan (total) dari rincian harian, termasuk laju per minggu dan per
     * hari kerja supaya perbandingan antar tipe jadwal (reguler / reguler staff /
     * shift) menjadi setara — total mentah tidak bisa dibandingkan langsung
     * karena jumlah hari kerjanya berbeda.
     *
     * @param  array<int, array<string, mixed>> $harian Rincian dari harian()
     * @return array<string, int|float|null> Ringkasan total menit, target, hari, dan laju
     */
    public function ringkasan(array $harian): array
    {
        $totalMenit  = 0;
        $totalTarget = 0;
        $hariKerja   = 0;
        $hariLibur   = 0;
        $terpenuhi   = 0;
        $kurang      = 0;
        $lebih       = 0;
        $kerjaOff    = 0;
        $luarShift   = 0;

        foreach ($harian as $baris) {
            $totalMenit += $baris['menit'];

            if ($baris['target_menit'] > 0) {
                $hariKerja++;
                $totalTarget += $baris['target_menit'];
            } else {
                $hariLibur++;
            }

            if ($baris['status'] === 'terpenuhi') {
                $terpenuhi++;
            } elseif ($baris['status'] === 'kurang') {
                $kurang++;
            } elseif ($baris['status'] === 'lebih') {
                $lebih++;
            }

            foreach ($baris['catatan'] as $catatan) {
                if (str_contains($catatan, 'hari OFF')) {
                    $kerjaOff++;
                }

                if (str_contains($catatan, 'di luar jam')) {
                    $luarShift++;
                }
            }
        }

        // Laju: dibagi jumlah hari dalam rentang (per minggu) dan jumlah hari
        // kerja menurut jadwal (per hari kerja).
        $jumlahHari   = max(1, count($harian));
        $minggu       = $jumlahHari / 7;
        $pembagiKerja = max(1, $hariKerja);

        return [
            'hari_kerja'                => $hariKerja,
            'hari_libur'                => $hariLibur,
            'jumlah_hari'               => $jumlahHari,
            'total_menit'               => $totalMenit,
            'total_target_menit'        => $totalTarget,
            'persen'                    => $totalTarget > 0 ? (int) round($totalMenit / $totalTarget * 100) : null,
            'hari_terpenuhi'            => $terpenuhi,
            'hari_kurang'               => $kurang,
            'hari_lebih'                => $lebih,
            'hari_kerja_off'            => $kerjaOff,
            'laporan_luar_shift'        => $luarShift,
            'realisasi_per_minggu'      => round($totalMenit / 60 / $minggu, 1),
            'target_per_minggu'         => round($totalTarget / 60 / $minggu, 1),
            'realisasi_per_hari_kerja'  => round($totalMenit / 60 / $pembagiKerja, 1),
            'target_per_hari_kerja'     => round($totalTarget / 60 / $pembagiKerja, 1),
        ];
    }

    /**
     * Total menit laporan (tanpa baris salinan multi alat) per teknisi pada
     * rentang tanggal — dipakai untuk kolom ringkas di daftar teknisi.
     *
     * @param  array<int, int> $teknisiIds Daftar ID teknisi
     * @param  CarbonInterface $mulai      Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai    Tanggal akhir (inklusif)
     * @return array<int, int>             Peta ID teknisi => total menit
     */
    public function menitPerTeknisi(array $teknisiIds, CarbonInterface $mulai, CarbonInterface $selesai): array
    {
        if ($teknisiIds === []) {
            return [];
        }

        return Report::query()
            ->whereIn('technician_id', $teknisiIds)
            ->whereNull('multi_of')
            ->whereBetween('report_date', [$mulai->toDateString() . ' 00:00:00', $selesai->toDateString() . ' 23:59:59'])
            ->groupBy('technician_id')
            ->selectRaw('technician_id, SUM(COALESCE(work_duration_minutes, 0)) as total_menit')
            ->pluck('total_menit', 'technician_id')
            ->map(fn ($nilai): int => (int) $nilai)
            ->all();
    }

    /**
     * Total target menit per teknisi pada rentang tanggal: baris jadwal import
     * dipakai lebih dulu, tanggal yang belum ada diisi proyeksi pola rotasi
     * (pekerja shift) atau jam kerja non-shift (reguler / reguler staff).
     *
     * @param  array<int, int> $teknisiIds Daftar ID teknisi
     * @param  CarbonInterface $mulai      Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai    Tanggal akhir (inklusif)
     * @return array<int, int>             Peta ID teknisi => total target menit
     */
    public function targetPerTeknisi(array $teknisiIds, CarbonInterface $mulai, CarbonInterface $selesai): array
    {
        if ($teknisiIds === []) {
            return [];
        }

        $teknisi = Technician::query()
            ->whereIn('id', $teknisiIds)
            ->get(['id', 'group'])
            ->keyBy('id');

        $hasil         = [];
        $tanggalTerisi = [];

        TechnicianShift::query()
            ->whereIn('technician_id', $teknisiIds)
            ->antara($mulai->toDateString(), $selesai->toDateString())
            ->get(['technician_id', 'shift_date', 'shift_code'])
            ->each(function (TechnicianShift $shift) use (&$hasil, &$tanggalTerisi, $teknisi): void {
                $id   = (int) $shift->technician_id;
                $grup = (string) ($teknisi[$id]->group ?? 'reguler');

                $hasil[$id] = ($hasil[$id] ?? 0) + $shift->targetMenit($grup);

                $tanggalTerisi[$id][$shift->shift_date->toDateString()] = true;
            });

        $pola = app(ShiftPatternService::class);
        $jam  = app(JamKerjaService::class);

        foreach ($teknisi as $id => $data) {
            $grup = (string) ($data->group ?? 'reguler');

            for ($tanggal = $mulai->copy()->startOfDay(); $tanggal->lte($selesai); $tanggal->addDay()) {
                $kunci = $tanggal->toDateString();

                if (isset($tanggalTerisi[$id][$kunci])) {
                    continue;
                }

                $kode = $pola->kode($grup, $tanggal);

                $menit = $kode !== null
                    ? ($kode === TechnicianShift::KODE_OFF ? 0 : (int) config('cm.man_hour.target_minutes', 480))
                    : $jam->targetMenit($grup, $tanggal);

                $hasil[$id] = ($hasil[$id] ?? 0) + $menit;
            }
        }

        return $hasil;
    }

    /**
     * Target menit satu hari: mengikuti kode shift bila ada baris jadwal, dan
     * memakai jam kerja non-shift (reguler / reguler staff) bila tidak ada.
     *
     * @param  TechnicianShift|null $shift       Baris jadwal hari itu (nullable)
     * @param  CarbonInterface      $tanggal     Tanggal yang dihitung
     * @param  string               $grupTeknisi Group teknisi
     * @return int                               Target menit
     */
    private function targetHari(?TechnicianShift $shift, CarbonInterface $tanggal, string $grupTeknisi): int
    {
        if ($shift !== null) {
            return $shift->targetMenit($grupTeknisi);
        }

        return app(JamKerjaService::class)->targetMenit($grupTeknisi, $tanggal);
    }

    /**
     * Status capaian satu hari.
     *
     * @param  int $target Target menit hari itu
     * @param  int $menit  Realisasi menit
     * @return string      'libur', 'kurang', 'terpenuhi', atau 'lebih'
     */
    private function statusHari(int $target, int $menit): string
    {
        if ($target <= 0) {
            return $menit > 0 ? 'lebih' : 'libur';
        }

        if ($menit <= 0 || $menit < $target) {
            return 'kurang';
        }

        return $menit > $target ? 'lebih' : 'terpenuhi';
    }

    /**
     * Hitung berapa laporan yang jam mulainya di luar jendela jam kerja hari itu
     * (dengan toleransi dari config).
     *
     * @param  array<int, Report> $laporan     Daftar laporan hari itu
     * @param  TechnicianShift    $shift       Baris jadwal hari itu
     * @param  string             $grupTeknisi Group teknisi
     * @return int                             Jumlah laporan di luar jam kerja
     */
    private function hitungLaporanLuarShift(array $laporan, TechnicianShift $shift, string $grupTeknisi): int
    {
        $jendela = $shift->jendela($grupTeknisi);

        if ($jendela === null) {
            return 0;
        }

        $toleransi = (int) config('cm.man_hour.toleransi_menit', 60);
        $mulai     = $this->menitDariJam((string) $jendela['mulai']);
        $selesai   = $this->menitDariJam((string) $jendela['selesai']);
        $jumlah    = 0;

        foreach ($laporan as $baris) {
            if (!$baris->start_time) {
                continue;
            }

            $menit = $this->menitDariJam((string) $baris->start_time);

            if ($menit < $mulai - $toleransi || $menit > $selesai + $toleransi) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * Ubah jam "HH:MM" (atau "24:00") menjadi menit sejak tengah malam.
     *
     * Mendelegasikan ke Report::menitDariJam() supaya aturan jam hanya ada di
     * satu tempat: sebelumnya memakai explode() sendiri sehingga nilai aneh
     * seperti "25:00" dihitung 1500 menit alih-alih ditolak. Format yang tidak
     * dikenali dianggap 0 menit supaya perhitungan anomali jadwal tetap jalan.
     *
     * @param  string $jam Jam format HH:MM
     * @return int         Menit sejak 00:00 (0 bila format tidak dikenali)
     */
    private function menitDariJam(string $jam): int
    {
        return Report::menitDariJam($jam) ?? 0;
    }
}
