<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Galat format berkas import Spare Part.
 *
 * Dilempar ketika kepala kolom (atau lembar) berkas sumber tidak sesuai
 * rancangan yang dikunci. Import dibatalkan seluruhnya supaya data yang sudah
 * benar tidak berubah karena berkas yang salah versi.
 */
class SparepartFormatException extends RuntimeException
{
    /**
     * Membuat galat dengan rincian judul kolom yang hilang dan yang ditemukan.
     *
     * @param  string            $berkas      Nama berkas yang diperiksa
     * @param  array<int,string> $hilang      Judul kolom/lembar yang wajib ada tetapi tidak ditemukan
     * @param  array<int,string> $ditemukan   Judul kolom yang benar-benar ditemukan
     * @param  string            $petunjuk    Saran perbaikan untuk pengguna
     * @return self                           Galat siap dilempar
     */
    public static function untukBerkas(string $berkas, array $hilang, array $ditemukan, string $petunjuk): self
    {
        $pesan = 'Format berkas ' . $berkas . ' tidak sesuai. '
            . ($hilang === [] ? '' : 'Tidak ditemukan: ' . implode(', ', $hilang) . '. ')
            . ($ditemukan === [] ? '' : 'Yang ditemukan: ' . implode(' | ', array_slice($ditemukan, 0, 30)) . '. ')
            . $petunjuk;

        $galat = new self(trim($pesan));

        $galat->berkas    = $berkas;
        $galat->hilang    = $hilang;
        $galat->ditemukan = $ditemukan;

        return $galat;
    }

    /** Nama berkas yang formatnya salah. */
    public string $berkas = '';

    /** Judul kolom yang wajib ada tetapi tidak ditemukan. */
    public array $hilang = [];

    /** Judul kolom yang benar-benar ditemukan pada berkas. */
    public array $ditemukan = [];

    /**
     * Judul kolom yang hilang, dipakai halaman import untuk menampilkan rincian.
     *
     * @return array<int, string>  Daftar judul kolom yang hilang
     */
    public function getHilang(): array
    {
        return $this->hilang;
    }

    /**
     * Judul kolom yang ditemukan, dipakai halaman import untuk pembanding.
     *
     * @return array<int, string>  Daftar judul kolom yang ditemukan
     */
    public function getDitemukan(): array
    {
        return $this->ditemukan;
    }
}
