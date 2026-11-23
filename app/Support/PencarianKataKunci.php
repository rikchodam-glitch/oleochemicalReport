<?php

namespace App\Support;

/**
 * PencarianKataKunci
 *
 * Aturan pemecahan kata kunci bersama untuk kotak pencarian halaman Asset
 * Management dan IT Assets. Tujuannya supaya kedua halaman (dan dropdown
 * sarannya) memakai batas minimal, jumlah token, serta pembersihan wildcard
 * LIKE yang sama.
 */
final class PencarianKataKunci
{
    /**
     * Bersihkan kata kunci dari karakter wildcard LIKE dan spasi berlebih.
     *
     * @param  string  $kataKunci  Kata kunci mentah dari pengguna
     */
    public static function bersihkan(string $kataKunci): string
    {
        $bersih = str_replace(['%', '_', '\\'], ' ', $kataKunci);
        $bersih = preg_replace('/\s+/u', ' ', $bersih) ?? '';

        return trim($bersih);
    }

    /**
     * Pecah kata kunci menjadi token pencarian (unik, minimal 2 huruf).
     *
     * Bila semua kata terlalu pendek, kata kunci utuh tetap dipakai selama
     * memenuhi panjang minimum supaya kode pendek seperti "P1" masih bisa
     * dicari.
     *
     * @param  string  $kataKunci  Kata kunci mentah
     * @param  int  $minimal  Panjang minimal satu token
     * @param  int  $maksimal  Jumlah token maksimal yang dipakai
     * @return array<int, string>
     */
    public static function token(string $kataKunci, int $minimal = 2, int $maksimal = 8): array
    {
        $bersih = self::bersihkan($kataKunci);

        if ($bersih === '') {
            return [];
        }

        $mentah = preg_split('/\s+/u', $bersih, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $token = [];

        foreach ($mentah as $kata) {
            if (mb_strlen($kata) >= $minimal) {
                $token[mb_strtolower($kata)] = $kata;
            }
        }

        if ($token === [] && mb_strlen($bersih) >= $minimal) {
            return [$bersih];
        }

        return array_slice(array_values($token), 0, max(1, $maksimal));
    }

    /**
     * Variasi pencarian satu kata: kata asli + sinonim dari konfigurasi.
     *
     * @param  string  $kata  Satu token pencarian
     * @param  array<string, array>  $sinonim  Peta sinonim (kunci huruf kecil)
     * @return array<int, string>
     */
    public static function variasi(string $kata, array $sinonim): array
    {
        $kunci = mb_strtolower($kata);
        $tambahan = $sinonim[$kunci] ?? [];

        return array_values(array_unique(array_merge([$kata], (array) $tambahan)));
    }
}
