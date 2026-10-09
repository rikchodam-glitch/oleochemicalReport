<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris data mentah monitoring stok minimum / stok tersedia satu material.
 *
 * Hanya baris dengan kode material berbentuk angka minimal 6 digit yang
 * dianggap sah (lihat SparepartImportService).
 */
class SpMonitoring extends Model
{
    protected $table = 'sp_monitoring';

    protected $fillable = [
        'batch_id',
        'kode_material',
        'deskripsi',
        'stok_min',
        'stok_tersedia',
        'reorder',
        'penanda',
    ];

    /**
     * Tipe data kolom monitoring.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stok_min'      => 'float',
            'stok_tersedia' => 'float',
            'reorder'       => 'boolean',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpMonitoring>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }
}
