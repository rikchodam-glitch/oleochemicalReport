<?php

namespace App\Support;

/**
 * WarnaJenisPekerjaan
 *
 * Satu sumber warna untuk kolom `reports.jenis_pekerjaan` (CM, dCM, PM, CM/PM,
 * EI, Mekanik, Fabrikasi, Sipil, IT, Project, UMUM). Dipakai bersama oleh:
 *   - kartu ringkasan (filter cepat) dan lencana pada halaman Laporan,
 *   - lencana jenis pekerjaan pada halaman detail laporan,
 *   - lencana jenis pekerjaan pada halaman detail teknisi,
 *   - grafik tren perbaikan + komposisi jenis pada halaman detail alat/lokasi
 *     (lewat TechnicianKinerjaService::WARNA_JENIS_PEKERJAAN),
 *   - kartu kinerja teknisi pada halaman detail laporan.
 *
 * Karena itu satu jenis pekerjaan selalu berwarna SAMA di semua tempat, baik di
 * filter (lencana/kartu) maupun di dalam grafik.
 *
 * Warna dipilih supaya:
 *   - tiap jenis punya ciri sendiri: jarak warna CIE76 antar jenis minimal 25
 *     (diperiksa tests/Feature/WarnaJenisPekerjaanTest.php),
 *   - Project memakai MERAH sesuai permintaan user,
 *   - kontras terhadap latar putih cukup: isi grafik minimal 2,5:1 dan teks
 *     lencana minimal 4,5:1 sehingga tidak menyatu dengan latar.
 *
 * Pemakaian umum:
 *   - isi grafik / garis / titik : WarnaJenisPekerjaan::hex($jenis)
 *   - teks lencana              : WarnaJenisPekerjaan::teks($jenis)
 *   - atribut style lencana     : WarnaJenisPekerjaan::gayaLencana($jenis)
 */
final class WarnaJenisPekerjaan
{
    /**
     * Warna utama tiap jenis pekerjaan (isi grafik, titik, dan garis).
     *
     * @var array<string, string>
     */
    public const PALET = [
        'CM'        => '#2563EB',
        'dCM'       => '#EA580C',
        'PM'        => '#16A34A',
        'CM/PM'     => '#0F766E',
        'EI'        => '#7C3AED',
        'Mekanik'   => '#CA8A04',
        'Fabrikasi' => '#86198F',
        'Sipil'     => '#92400E',
        'IT'        => '#DB2777',
        'Project'   => '#B91C1C',
        'UMUM'      => '#65A30D',
    ];

    /**
     * Warna cadangan untuk jenis kosong atau tidak dikenal (abu-abu netral).
     */
    public const CADANGAN = '#94A3B8';

    /**
     * Faktor penggelapan warna utama untuk teks lencana di atas latar muda.
     */
    private const FAKTOR_TEKS = 0.78;

    /**
     * Kepekatan latar lencana (0-1) terhadap warna utama.
     */
    private const ALFA_LATAR = 0.12;

    /**
     * Kepekatan garis lencana (0-1) terhadap warna utama.
     */
    private const ALFA_GARIS = 0.38;

    /**
     * Warna utama satu jenis pekerjaan (isi grafik, titik, garis).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Kode warna hex
     */
    public static function hex(?string $jenis): string
    {
        return self::PALET[trim((string) $jenis)] ?? self::CADANGAN;
    }

    /**
     * Warna TEKS lencana satu jenis pekerjaan: warna utama yang digelapkan
     * secukupnya supaya tetap terbaca di atas latar putih atau latar muda.
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Kode warna hex
     */
    public static function teks(?string $jenis): string
    {
        return self::gelapkan(self::hex($jenis), self::FAKTOR_TEKS);
    }

    /**
     * Warna LATAR lencana satu jenis pekerjaan (warna utama dengan kepekatan
     * rendah sehingga teks tetap kontras).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @param  float|null   $alfa   Kepekatan 0-1 (bawaan 0,12)
     * @return string  Warna rgba
     */
    public static function latar(?string $jenis, ?float $alfa = null): string
    {
        [$r, $g, $b] = self::rgb(self::hex($jenis));

        return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, self::angka($alfa ?? self::ALFA_LATAR));
    }

    /**
     * Isi atribut style lencana jenis pekerjaan (latar + teks + garis).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Potongan CSS siap dipakai pada atribut style
     */
    public static function gayaLencana(?string $jenis): string
    {
        return 'background-color: ' . self::latar($jenis)
            . '; color: ' . self::teks($jenis)
            . '; border-color: ' . self::latar($jenis, self::ALFA_GARIS);
    }

    /**
     * Isi atribut style untuk teks berwarna satu jenis pekerjaan (mis. angka
     * kartu ringkasan pada halaman Laporan).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Potongan CSS siap dipakai pada atribut style
     */
    public static function gayaTeks(?string $jenis): string
    {
        return 'color: ' . self::teks($jenis);
    }

    /**
     * Isi atribut style untuk GARIS berwarna satu jenis pekerjaan (mis. border
     * kartu ringkasan pada halaman Laporan).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Potongan CSS siap dipakai pada atribut style
     */
    public static function gayaGaris(?string $jenis): string
    {
        return 'border-color: ' . self::latar($jenis, self::ALFA_GARIS);
    }

    /**
     * Daftar jenis pekerjaan yang punya warna sendiri, urut sesuai PALET.
     *
     * @return array<int, string>
     */
    public static function jenisTersedia(): array
    {
        return array_keys(self::PALET);
    }

    /**
     * Pecah kode warna hex menjadi kanal merah, hijau, dan biru.
     *
     * @param  string  $hex  Kode warna hex (#RRGGBB)
     * @return array<int, int>  Kanal 0-255
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Gelapkan kode warna hex dengan mengalikan tiap kanal, hasilnya dibulatkan
     * dan tidak pernah melewati batas 0-255.
     *
     * @param  string  $hex     Kode warna hex (#RRGGBB)
     * @param  float   $faktor  Pengali kanal (0-1)
     * @return string  Kode warna hex hasil penggelapan (huruf besar)
     */
    private static function gelapkan(string $hex, float $faktor): string
    {
        $hasil = '#';

        foreach (self::rgb($hex) as $kanal) {
            $nilai = (int) round(max(0, min(255, $kanal * $faktor)));
            $hasil .= str_pad(strtoupper(dechex($nilai)), 2, '0', STR_PAD_LEFT);
        }

        return $hasil;
    }

    /**
     * Tulis angka pecahan tanpa nol berekor yang tidak perlu (0,12 bukan 0,120).
     *
     * @param  float  $nilai  Angka 0-1
     * @return string  Bentuk teks angka
     */
    private static function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 3, '.', ''), '0'), '.') ?: '0';
    }
}
