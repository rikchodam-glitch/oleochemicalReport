<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * AksesModul
 *
 * Pembantu untuk memetakan nama route ke modul hak akses yang didefinisikan
 * pada config/akses.php. Dipakai oleh AksesMiddleware (penegakan izin) dan
 * model User (menentukan menu yang boleh tampil).
 */
class AksesModul
{
    /**
     * Seluruh modul hak akses beserta keterangannya.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function semua(): array
    {
        return (array) config('akses.modul', []);
    }

    /**
     * Cari kunci modul yang cocok dengan sebuah nama route.
     *
     * Bila satu route cocok dengan beberapa pola, dipakai pola yang paling
     * panjang (paling spesifik), misal it-assets.ip-addresses.* dipilih
     * daripada it-assets.*.
     *
     * @param  string|null $namaRoute Nama route yang sedang diakses
     * @return string|null            Kunci modul, null bila route bebas modul
     */
    public static function kunciUntukRoute(?string $namaRoute): ?string
    {
        if ($namaRoute === null || $namaRoute === '') {
            return null;
        }

        $kunciTerpilih   = null;
        $panjangTerpilih = -1;

        foreach (self::semua() as $kunci => $modul) {
            foreach ((array) ($modul['pola'] ?? []) as $pola) {
                if (! Str::is($pola, $namaRoute)) {
                    continue;
                }

                if (strlen((string) $pola) > $panjangTerpilih) {
                    $kunciTerpilih   = (string) $kunci;
                    $panjangTerpilih = strlen((string) $pola);
                }
            }
        }

        return $kunciTerpilih;
    }

    /**
     * Keterangan satu modul.
     *
     * @param  string $kunci Kunci modul
     * @return array<string, mixed>|null
     */
    public static function modul(string $kunci): ?array
    {
        return self::semua()[$kunci] ?? null;
    }

    /**
     * Apakah modul hanya boleh diakses role admin (tidak bisa diberikan ke
     * Bagian mana pun).
     *
     * @param  string $kunci Kunci modul
     * @return bool
     */
    public static function khususAdmin(string $kunci): bool
    {
        return (bool) (self::modul($kunci)['khusus_admin'] ?? false);
    }

    /**
     * Label modul untuk ditampilkan di layar.
     *
     * @param  string $kunci Kunci modul
     * @return string
     */
    public static function label(string $kunci): string
    {
        return (string) (self::modul($kunci)['label'] ?? $kunci);
    }

    /**
     * Nama route menu sidebar modul ini (null bila tidak ada menunya).
     *
     * @param  string $kunci Kunci modul
     * @return string|null
     */
    public static function link(string $kunci): ?string
    {
        $link = self::modul($kunci)['link'] ?? null;

        return $link === null ? null : (string) $link;
    }

    /**
     * Modul dikelompokkan berdasarkan kolom grup (untuk tampilan pengaturan).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function perGrup(): array
    {
        $hasil = [];

        foreach (self::semua() as $kunci => $modul) {
            $grup = (string) ($modul['grup'] ?? 'Lainnya');
            $hasil[$grup][$kunci] = $modul;
        }

        return $hasil;
    }
}
