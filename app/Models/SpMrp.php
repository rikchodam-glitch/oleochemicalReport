<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris data mentah MD07 (plant stock, safety stock, reorder point) per material.
 */
class SpMrp extends Model
{
    protected $table = 'sp_mrp';

    protected $fillable = [
        'batch_id',
        'kode_material',
        'plant_stock',
        'safety_stock',
        'reorder_point',
    ];

    /**
     * Tipe data kolom MRP.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plant_stock'   => 'float',
            'safety_stock'  => 'float',
            'reorder_point' => 'float',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpMrp>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }
}
