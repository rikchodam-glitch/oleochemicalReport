<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hak akses halaman per Bagian (section teknisi).
 *
 * Satu baris mewakili satu Bagian; kolom permissions berisi daftar kunci modul
 * (lihat config/akses.php) yang boleh diakses Bagian tersebut.
 */
class BagianAccess extends Model
{
    protected $fillable = [
        'bagian',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /**
     * Ambil baris hak akses untuk satu Bagian.
     *
     * Bila barisnya belum ada (misalnya Bagian baru yang belum pernah diatur),
     * seluruh modul dianggap boleh diakses supaya perilaku bawaan aplikasi
     * tetap sama seperti sebelum fitur hak akses dipakai.
     *
     * @param  string|null $bagian Kode Bagian (kolom section teknisi)
     * @return self                Baris hak akses, bisa belum tersimpan
     */
    public static function untukBagian(?string $bagian): self
    {
        $kode = (string) $bagian;

        $baris = self::query()->where('bagian', $kode)->first();

        if ($baris !== null) {
            return $baris;
        }

        $baris              = new self(['bagian' => $kode]);
        $baris->permissions = array_keys(config('akses.modul', []));

        return $baris;
    }

    /**
     * Daftar kunci modul yang diizinkan untuk Bagian ini.
     *
     * @return array<int, string>
     */
    public function daftarModul(): array
    {
        return array_values((array) ($this->permissions ?? []));
    }

    /**
     * Apakah Bagian ini boleh mengakses modul tertentu.
     *
     * @param  string $kunci Kunci modul pada config/akses.php
     * @return bool
     */
    public function punya(string $kunci): bool
    {
        return in_array($kunci, $this->daftarModul(), true);
    }
}
