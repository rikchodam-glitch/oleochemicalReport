<?php

namespace App\Services;

use App\Models\Report;
use App\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TechnicianKinerjaService
 *
 * Menyiapkan data kinerja satu atau beberapa teknisi untuk kartu "Teknisi
 * Pelaksana" pada halaman detail laporan: profil ringkas, jumlah laporan,
 * deret jumlah laporan per bulan (12 bulan terakhir), dan komposisi jenis
 * pekerjaan.
 *
 * Aturan hitungan yang dipakai (konsisten dengan daftar laporan dan man hour):
 *   - Hanya laporan yang dimiliki teknisi itu (reports.technician_id).
 *   - Baris salinan multi alat (multi_of terisi) TIDAK dihitung supaya satu
 *     pekerjaan yang menyentuh banyak alat tidak menggelembungkan angka.
 *   - Baris salinan kolaborasi TETAP dihitung untuk teknisi kolaboratornya,
 *     karena baris itu memang menandai pekerjaan yang ikut dikerjakan.
 *
 * Seluruh data diambil dengan agregasi SQL (satu kali per kelompok data),
 * bukan satu query per teknisi, supaya halaman detail laporan tetap ringan
 * walau laporan itu berkolaborasi sampai lima teknisi.
 */
class TechnicianKinerjaService
{
    /**
     * Jumlah bulan deret grafik kinerja (termasuk bulan berjalan).
     */
    public const JUMLAH_BULAN = 12;

    /**
     * Warna penanda jenis pekerjaan. Satu sumber warna untuk seluruh aplikasi:
     * trait ResolvesReportAnalytics (grafik perbaikan alat/lokasi) memakai
     * konstanta ini juga, jadi warna satu jenis tidak pernah berbeda antar
     * halaman.
     *
     * @var array<string, string>
     */
    public const WARNA_JENIS_PEKERJAAN = [
        'CM'        => '#EF4444',
        'dCM'       => '#F97316',
        'PM'        => '#14B8A6',
        'CM/PM'     => '#0E9E8E',
        'EI'        => '#6366F1',
        'Mekanik'   => '#F59E0B',
        'Fabrikasi' => '#8B5CF6',
        'Sipil'     => '#06B6D4',
        'IT'        => '#EC4899',
        'Project'   => '#64748B',
    ];

    /**
     * Data kinerja sekumpulan teknisi, dikunci dengan id teknisi.
     *
     * @param  array<int, int>  $idTeknisi  Daftar id teknisi (duplikat diabaikan)
     * @return array<int, array<string, mixed>> Peta id teknisi => data kinerja
     */
    public function ringkasUntuk(array $idTeknisi): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idTeknisi))));

        if ($ids === []) {
            return [];
        }

        $teknisi = Technician::with('department:id,code,name')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'nik', 'group', 'section', 'status', 'department_id'])
            ->keyBy('id');

        // Dasar hitungan: laporan milik teknisi, tanpa baris salinan multi alat.
        $dasar = Report::whereIn('technician_id', $ids)->whereNull('multi_of');

        $perStatus = (clone $dasar)
            ->select('technician_id', 'status', DB::raw('count(*) as jumlah'))
            ->groupBy('technician_id', 'status')
            ->get();

        $bulanAwal  = Carbon::now()->startOfMonth()->subMonths(self::JUMLAH_BULAN - 1);
        $slotBulan  = [];
        $labelBulan = [];

        for ($i = 0; $i < self::JUMLAH_BULAN; $i++) {
            $bulan        = $bulanAwal->copy()->addMonths($i);
            $slotBulan[]  = $bulan->format('Y-m');
            $labelBulan[] = $bulan->format('M Y');
        }

        $perBulan = (clone $dasar)
            ->whereBetween('report_date', [
                $bulanAwal->toDateString() . ' 00:00:00',
                Carbon::now()->endOfMonth()->toDateString() . ' 23:59:59',
            ])
            ->select('technician_id', DB::raw($this->formatBulanSql() . ' as bulan'), DB::raw('count(*) as jumlah'))
            ->groupBy('technician_id', 'bulan')
            ->get();

        $perJenis = (clone $dasar)
            ->select('technician_id', 'jenis_pekerjaan', DB::raw('count(*) as jumlah'))
            ->groupBy('technician_id', 'jenis_pekerjaan')
            ->get();

        $hasil = [];

        foreach ($ids as $id) {
            $profil = $teknisi->get($id);

            // Teknisi yang datanya sudah terhapus tidak ditampilkan sama sekali
            // supaya kartu tidak menampilkan nama kosong.
            if ($profil === null) {
                continue;
            }

            $hasil[$id] = $this->susunSatuTeknisi(
                $profil,
                $perStatus->where('technician_id', $id),
                $perBulan->where('technician_id', $id),
                $perJenis->where('technician_id', $id),
                $slotBulan,
                $labelBulan
            );
        }

        return $hasil;
    }

    /**
     * Susun data kinerja satu teknisi dari baris agregat yang sudah dikumpulkan
     * pemanggilnya (satu query untuk semua teknisi, bukan satu query per orang).
     *
     * @param  Technician                 $profil      Data teknisi
     * @param  \Illuminate\Support\Collection  $barisStatus  Agregat per status
     * @param  \Illuminate\Support\Collection  $barisBulan   Agregat per bulan
     * @param  \Illuminate\Support\Collection  $barisJenis   Agregat per jenis pekerjaan
     * @param  array<int, string>         $slotBulan   Daftar bulan slot (YYYY-MM)
     * @param  array<int, string>         $labelBulan  Label bulan slot (Mmm YYYY)
     * @return array<string, mixed>                   Data siap dikirim ke tampilan
     */
    private function susunSatuTeknisi(
        Technician $profil,
        $barisStatus,
        $barisBulan,
        $barisJenis,
        array $slotBulan,
        array $labelBulan
    ): array {
        $jumlahStatus = [];

        foreach ($barisStatus as $baris) {
            $jumlahStatus[$baris->status] = (int) $baris->jumlah;
        }

        $deret = array_fill(0, self::JUMLAH_BULAN, 0);

        foreach ($barisBulan as $baris) {
            $posisi = array_search((string) $baris->bulan, $slotBulan, true);

            if ($posisi !== false) {
                $deret[$posisi] = (int) $baris->jumlah;
            }
        }

        $komposisi = $barisJenis
            ->map(fn ($baris) => [
                'label'  => $this->labelJenis($baris->jenis_pekerjaan),
                'jumlah' => (int) $baris->jumlah,
                'color'  => $this->warnaJenis($baris->jenis_pekerjaan),
            ])
            ->sortByDesc('jumlah')
            ->values()
            ->all();

        return [
            'id'             => (int) $profil->id,
            'nama'           => (string) $profil->name,
            'inisial'        => $this->inisial($profil->name),
            'nik'            => $profil->nik,
            'bagian'         => Technician::SECTIONS[$profil->section] ?? ($profil->section ?: '-'),
            'grup'           => Technician::GROUPS[$profil->group] ?? ($profil->group ?: '-'),
            'status'         => $this->labelStatus($profil->status),
            'departemen'     => $profil->department?->name,
            'total'          => array_sum($jumlahStatus),
            'selesai'        => $jumlahStatus['completed'] ?? 0,
            'belum_selesai'  => $jumlahStatus['continue'] ?? 0,
            'perlu_review'   => $jumlahStatus['needs_review'] ?? 0,
            'bulan_ini'      => $deret[self::JUMLAH_BULAN - 1] ?? 0,
            'label_bulan'    => $labelBulan,
            'jumlah_bulanan' => $deret,
            'komposisi'      => $komposisi,
            'tautan'         => route('technicians.show', $profil->id),
        ];
    }

    /**
     * Ekspresi SQL format bulan (YYYY-MM) sesuai driver database yang aktif.
     * Aturan sama dengan ResolvesReportAnalytics supaya hasil di MySQL dan
     * SQLite (dipakai pengujian) tidak berbeda.
     *
     * @return string  Potongan SQL siap dipakai pada SELECT
     */
    private function formatBulanSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', report_date)"
            : "DATE_FORMAT(report_date, '%Y-%m')";
    }

    /**
     * Label tampilan satu jenis pekerjaan (nilai kosong menjadi "Tanpa Jenis").
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string
     */
    private function labelJenis(?string $jenis): string
    {
        $jenis = trim((string) $jenis);

        return $jenis === '' ? 'Tanpa Jenis' : $jenis;
    }

    /**
     * Warna satu jenis pekerjaan (jenis tak dikenal memakai abu-abu).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Kode warna hex
     */
    private function warnaJenis(?string $jenis): string
    {
        return self::WARNA_JENIS_PEKERJAAN[trim((string) $jenis)] ?? '#94A3B8';
    }

    /**
     * Label status akun teknisi.
     *
     * @param  string|null  $status  Nilai kolom technicians.status
     * @return string
     */
    private function labelStatus(?string $status): string
    {
        return match ($status) {
            'active'  => 'Aktif',
            'pending' => 'Menunggu Persetujuan',
            default   => 'Nonaktif',
        };
    }

    /**
     * Inisial nama teknisi untuk avatar kartu (maksimal dua huruf).
     *
     * @param  string|null  $nama  Nama teknisi
     * @return string
     */
    private function inisial(?string $nama): string
    {
        $kata = preg_split('/\s+/', trim((string) $nama)) ?: [];
        $kata = array_slice(array_filter($kata), 0, 2);

        if ($kata === []) {
            return '?';
        }

        return strtoupper(implode('', array_map(fn ($bagian) => mb_substr($bagian, 0, 1), $kata)));
    }
}
