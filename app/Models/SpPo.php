<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris data mentah pesanan pembelian (EXPORT PO) satu baris item.
 */
class SpPo extends Model
{
    protected $table = 'sp_po';

    protected $fillable = [
        'batch_id',
        'kode_material',
        'no_po',
        'tanggal_dokumen',
        'sisa_kirim',
        'status',
        'vendor',
        'our_reference',
        'plant',
        'order_qty',
    ];

    /**
     * Tipe data kolom PO.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_dokumen' => 'date',
            'sisa_kirim'      => 'float',
            'order_qty'       => 'float',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpPo>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }

    /**
     * Apakah PO masih terbuka (belum selesai kirim)?
     *
     * @return bool  true bila sisa kirim masih lebih besar dari nol
     */
    public function getTerbukaAttribute(): bool
    {
        return (float) ($this->sisa_kirim ?? 0) > 0;
    }
}
