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

            $target      = $this->targetHari($shift, $tanggal);
            $jumlahKerja = count($laporanHari);

            if ($shift !== null && $shift->isOff() && $jumlahKerja > 0) {
                $catatan[] = 'Ada ' . $jumlahKerja . ' pekerjaan pada hari OFF';
            }

            if ($shift !== null && !$shift->isOff() && $jumlahKerja > 0) {
                $luar = $this->hitungLaporanLuarShift($laporanHari, $shift);

                if ($luar > 0) {
                    $catatan[] = $luar . ' laporan di luar jam ' . $shift->label();
                }
            }

            $rincian[] = [
                'tanggal'          => $kunci,
                'hari'             => $tanggal->translatedFormat('l'),
                'kode'             => $shift?->kode(),
                'label_shift'      => $shift?->label() ?? 'Tanpa jadwal',
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
     * Ringkasan (total) dari rincian harian.
     *
     * @param  array<int, array<string, mixed>> $harian Rincian dari harian()
     * @return array<string, int|null> Ringkasan total menit, target, dan jumlah hari
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

        return [
            'hari_kerja'         => $hariKerja,
            'hari_libur'         => $hariLibur,
            'total_menit'        => $totalMenit,
            'total_target_menit' => $totalTarget,
            'persen'             => $totalTarget > 0 ? (int) round($totalMenit / $totalTarget * 100) : null,
            'hari_terpenuhi'     => $terpenuhi,
            'hari_kurang'        => $kurang,
            'hari_lebih'         => $lebih,
            'hari_kerja_off'     => $kerjaOff,
            'laporan_luar_shift' => $luarShift,
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
     * Total target menit per teknisi sesuai jadwal shift pada rentang tanggal.
     * Teknisi tanpa baris jadwal tidak muncul di hasil (target belum diketahui).
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

        $hasil = [];

        TechnicianShift::query()
            ->whereIn('technician_id', $teknisiIds)
            ->antara($mulai->toDateString(), $selesai->toDateString())
            ->select(['technician_id', 'shift_date', 'shift_code'])
            ->get()
            ->each(function (TechnicianShift $shift) use (&$hasil): void {
                $id = (int) $shift->technician_id;

                $hasil[$id] = ($hasil[$id] ?? 0) + $shift->targetMenit();
            });

        return $hasil;
    }

    /**
     * Target menit satu hari: 0 untuk hari OFF / Minggu reguler, selain itu
     * target harian dari config (hari tanpa baris jadwal dianggap hari kerja).
     *
     * @param  TechnicianShift|null $shift   Baris jadwal hari itu (nullable)
     * @param  CarbonInterface      $tanggal Tanggal yang dihitung
     * @return int                           Target menit
     */
    private function targetHari(?TechnicianShift $shift, CarbonInterface $tanggal): int
    {
        if ($shift !== null) {
            return $shift->targetMenit();
        }

        if ((bool) config('cm.man_hour.minggu_libur_reguler', true) && $tanggal->dayOfWeek === 0) {
            return 0;
        }

        return (int) config('cm.man_hour.target_minutes', 480);
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
     * Hitung berapa laporan yang jam mulainya di luar jendela shift hari itu
     * (dengan toleransi dari config).
     *
     * @param  array<int, Report> $laporan Daftar laporan hari itu
     * @param  TechnicianShift    $shift   Baris jadwal hari itu
     * @return int                         Jumlah laporan di luar jam shift
     */
    private function hitungLaporanLuarShift(array $laporan, TechnicianShift $shift): int
    {
        $jendela = $shift->jendela();

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
     * @param  string $jam Jam format HH:MM
     * @return int         Menit sejak 00:00
     */
    private function menitDariJam(string $jam): int
    {
        $potongan = explode(':', $jam);

        return ((int) ($potongan[0] ?? 0)) * 60 + ((int) ($potongan[1] ?? 0));
    }
}
