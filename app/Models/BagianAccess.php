<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hak akses halaman per Bagian (section teknisi) dan per Group teknisi.
 *
 * Satu baris mewakili satu kombinasi Bagian + Group; kolom permissions berisi
 * daftar kunci modul (lihat config/akses.php) yang boleh diakses kombinasi itu.
 * Kolom grup bernilai NULL berarti pengaturan bawaan Bagian: dipakai akun yang
 * tidak terhubung teknisi dan Group yang belum diatur sendiri.
 */
class BagianAccess extends Model
{
    protected $fillable = [
        'bagian',
        'grup',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /**
     * Ambil baris hak akses untuk satu Bagian pada satu Group teknisi.
     *
     * Urutan pencarian:
     * 1. baris Bagian + Group (mis. mekanik + reguler_staff) bila sudah diatur;
     * 2. baris Bagian tanpa Group (pengaturan bawaan Bagian) bila Group tersebut
     *    belum diatur sendiri;
     * 3. seluruh modul dianggap boleh diakses supaya perilaku bawaan aplikasi
     *    tetap sama seperti sebelum fitur hak akses dipakai.
     *
     * @param  string|null $bagian Kode Bagian (kolom section teknisi)
     * @param  string|null $grup   Kode Group teknisi (lihat Technician::GROUPS)
     * @return self                Baris hak akses, bisa belum tersimpan
     */
    public static function untukBagian(?string $bagian, ?string $grup = null): self
    {
        $kode      = (string) $bagian;
        $kodeGrup  = self::grupRapi($grup);

        $baris = self::tersimpan($kode, $kodeGrup);

        if ($baris === null && $kodeGrup !== null) {
            $baris = self::tersimpan($kode, null);
        }

        if ($baris !== null) {
            return $baris;
        }

        $baris              = new self(['bagian' => $kode, 'grup' => $kodeGrup]);
        $baris->permissions = array_keys(config('akses.modul', []));

        return $baris;
    }

    /**
     * Baris hak akses yang benar-benar tersimpan di database, tanpa mengambil
     * pengaturan bawaan Bagian. Dipakai layar pengaturan untuk menandai
     * kombinasi yang belum pernah diatur dan dipakai oleh pengujian.
     *
     * @param  string|null $bagian Kode Bagian
     * @param  string|null $grup   Kode Group (null berarti pengaturan bawaan Bagian)
     * @return self|null           Baris tersimpan, null bila belum ada
     */
    public static function tersimpan(?string $bagian, ?string $grup = null): ?self
    {
        return self::query()
            ->where('bagian', (string) $bagian)
            ->where('grup', self::grupRapi($grup))
            ->first();
    }

    /**
     * Rapikan kode Group: teks kosong dianggap null supaya kolom grup bernilai
     * NULL tetap berarti "berlaku untuk semua Group Bagian ini".
     *
     * @param  string|null $grup Kode Group mentah
     * @return string|null       Kode Group yang sudah dirapikan
     */
    private static function grupRapi(?string $grup): ?string
    {
        $kode = trim((string) $grup);

        return $kode === '' ? null : $kode;
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
