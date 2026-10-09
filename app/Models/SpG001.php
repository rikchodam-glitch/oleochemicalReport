<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris data mentah stok gudang (laporan MB52/G001) satu material per SLoc.
 */
class SpG001 extends Model
{
    protected $table = 'sp_g001';

    protected $fillable = [
        'batch_id',
        'kode_material',
        'sloc',
        'nama_item',
        'stok_awal',
        'masuk',
        'keluar',
        'sisa',
        'satuan',
    ];

    /**
     * Tipe data kolom stok gudang.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stok_awal' => 'float',
            'masuk'     => 'float',
            'keluar'    => 'float',
            'sisa'      => 'float',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpG001>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }
}
