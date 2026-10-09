<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu baris daftar pantau Spare Part.
 *
 * Daftar pantau adalah SUMBER POPULASI halaman Integrasi PR/PO: hanya kode
 * material yang ada di tabel ini yang ditampilkan dan dihitung. Isinya berasal
 * dari sheet MASTER DATA berkas SparePart_Monitoring.xlsx (impor) atau
 * diketik manual pada halaman daftar pantau.
 *
 * Waktu import, pertanyaan pertama selalu datanya sendiri (tidak menimpa angka
 * yang diisi manual).
 */
class SpPantau extends Model
{
    protected $table = 'sp_pantau';

    /**
     * Nilai sumber baris daftar pantau: berasal dari berkas MASTER DATA.
     */
    public const SUMBER_MASTER = 'master';

    /**
     * Nilai sumber baris daftar pantau: diketik/diubah manual lewat halaman.
     */
    public const SUMBER_MANUAL = 'manual';

    protected $fillable = [
        'kode_material',
        'deskripsi',
        'plant',
        'model',
        'tag_no',
        'uom',
        'stok_min',
        'sumber',
        'aktif',
        'catatan',
    ];

    /**
     * Tipe data kolom daftar pantau.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stok_min' => 'float',
            'aktif'    => 'boolean',
        ];
    }

    /**
     * Keadaan terkini material ini (boleh belum ada bila belum pernah
     * muncul pada berkas mana pun).
     *
     * @return HasOne<SpMaterial>
     */
    public function keadaan(): HasOne
    {
        return $this->hasOne(SpMaterial::class, 'kode_material', 'kode_material');
    }

    /**
     * Label sumber baris untuk tampilan.
     *
     * @return string  Label sumber (Master Data atau Manual)
     */
    public function getLabelSumberAttribute(): string
    {
        return $this->sumber === self::SUMBER_MANUAL ? 'Manual' : 'Master Data';
    }
}
