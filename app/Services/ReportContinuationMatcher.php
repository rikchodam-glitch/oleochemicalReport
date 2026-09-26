<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Support\Carbon;

/**
 * ReportContinuationMatcher
 *
 * Mencari laporan ber-status 'continue' yang kemungkinan besar merupakan asal
 * pekerjaan yang sedang dilaporkan sekarang, supaya teknisi yang menulis
 * laporan biasa (tanpa mengetik kode laporan) tetap bisa menautkan laporannya
 * dengan pekerjaan lanjutan beberapa hari sebelumnya.
 *
 * Dasar pencocokan (sesuai kebutuhan bisnis): deskripsi pekerjaan, root cause,
 * serta lokasi/equipment. Pencocokan ini hanya MENAWARKAN kandidat — penautan
 * tetap dilakukan setelah teknisi menekan tombol konfirmasi di Step 8, supaya
 * tidak ada laporan yang tertaut keliru.
 */
class ReportContinuationMatcher
{
    /**
     * Batas kemiripan deskripsi (%) agar sebuah kandidat dianggap layak tawar.
     */
    private const AMBANG_DESKRIPSI = 55.0;

    /**
     * Batas kemiripan root cause (%) sebagai jalur alternatif.
     */
    private const AMBANG_ROOT_CAUSE = 60.0;

    /**
     * Rentang hari ke belakang untuk mencari laporan continue yang masih terbuka.
     */
    private const HARI_KE_BELAKANG = 30;

    /**
     * Cari kandidat laporan lanjutan yang paling mirip.
     *
     * @param  array  $data  Data laporan yang sedang dibuat: text (deskripsi),
     *                       root_cause, equipment_id, funcloc_id, area_id
     * @return Report|null  Laporan 'continue' paling mirip, atau null bila tidak ada
     */
    public function cariKandidat(array $data): ?Report
    {
        $deskripsi  = trim((string) ($data['text'] ?? ''));
        $rootCause  = trim((string) ($data['root_cause'] ?? ''));

        if ($deskripsi === '' && $rootCause === '') {
            return null;
        }

        $kandidat = $this->queryKandidat($data)->get();

        if ($kandidat->isEmpty()) {
            return null;
        }

        $terbaik      = null;
        $skorTerbaik  = 0.0;

        foreach ($kandidat as $laporan) {
            $skor = $this->hitungSkor(
                $deskripsi,
                $rootCause,
                (string) $laporan->work_description,
                (string) $laporan->root_cause
            );

            if ($skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik     = $laporan;
            }
        }

        return $skorTerbaik > 0 ? $terbaik : null;
    }

    /**
     * Query dasar kandidat: laporan 'continue' yang belum dilanjutkan siapa pun,
     * dalam rentang hari terakhir, dan (bila diketahui) pada lokasi/alat yang sama.
     *
     * @param  array  $data  Data laporan yang sedang dibuat
     * @return \Illuminate\Database\Eloquent\Builder<Report>
     */
    private function queryKandidat(array $data)
    {
        $query = Report::query()
            ->belumDilanjutkan()
            ->where('report_date', '>=', Carbon::now()->subDays(self::HARI_KE_BELAKANG)->toDateString());

        $query->where(function ($q) use ($data) {
            $adaKriteria = false;

            if (!empty($data['equipment_id'])) {
                $q->orWhere('asset_id', (int) $data['equipment_id']);
                $adaKriteria = true;
            }

            if (!empty($data['funcloc_id'])) {
                $q->orWhere('funcloc_id', (int) $data['funcloc_id']);
                $adaKriteria = true;
            }

            if (!empty($data['area_id'])) {
                $q->orWhere('area_id', (int) $data['area_id']);
                $adaKriteria = true;
            }

            // Tanpa lokasi/alat sama sekali (pekerjaan umum): semua laporan
            // continue dalam rentang hari dianggap kandidat, penilaian
            // kemiripan deskripsi/root cause yang menentukan.
            if (!$adaKriteria) {
                $q->whereNotNull('id');
            }
        });

        return $query->orderByDesc('report_date');
    }

    /**
     * Hitung skor kemiripan gabungan deskripsi & root cause (0 = tidak layak).
     *
     * @param  string  $deskripsiBaru    Deskripsi laporan yang sedang dibuat
     * @param  string  $rootCauseBaru    Root cause laporan yang sedang dibuat
     * @param  string  $deskripsiLama    Deskripsi kandidat
     * @param  string  $rootCauseLama    Root cause kandidat
     * @return float  Skor kemiripan (persen); 0 berarti di bawah ambang
     */
    private function hitungSkor(
        string $deskripsiBaru,
        string $rootCauseBaru,
        string $deskripsiLama,
        string $rootCauseLama
    ): float {
        $kemiripanDeskripsi = $this->kemiripan($deskripsiBaru, $deskripsiLama);
        $kemiripanRootCause = $this->kemiripan($rootCauseBaru, $rootCauseLama);

        if ($kemiripanDeskripsi >= self::AMBANG_DESKRIPSI) {
            // Deskripsi mirip: root cause dipakai sebagai penguat skor.
            return $kemiripanDeskripsi + ($kemiripanRootCause / 10);
        }

        if ($kemiripanRootCause >= self::AMBANG_ROOT_CAUSE) {
            return $kemiripanRootCause;
        }

        return 0.0;
    }

    /**
     * Hitung persentase kemiripan dua teks (0-100).
     *
     * @param  string  $a  Teks pertama
     * @param  string  $b  Teks kedua
     * @return float  Persentase kemiripan
     */
    private function kemiripan(string $a, string $b): float
    {
        $a = $this->normalisasi($a);
        $b = $this->normalisasi($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $persen);

        return (float) $persen;
    }

    /**
     * Normalisasi teks: huruf kecil, tanda baca dibuang, spasi dirapikan.
     *
     * @param  string  $teks  Teks asli
     * @return string  Teks ternormalisasi
     */
    private function normalisasi(string $teks): string
    {
        $teks = mb_strtolower($teks);
        $teks = preg_replace('/[^a-z0-9 ]+/u', ' ', $teks) ?? '';

        return trim(preg_replace('/\s+/', ' ', $teks) ?? '');
    }
}
