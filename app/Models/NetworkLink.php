<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkLink extends Model
{
    /**
     * Kolom yang boleh diisi massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'layout_id',
        'from_asset_id',
        'to_asset_id',
        'cable_type',
        'path_points',
        'label',
        'notes',
    ];

    /**
     * Casting tipe data.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'path_points' => 'array',
        ];
    }

    /**
     * Layout (denah) tempat jalur ini digambar.
     *
     * @return BelongsTo<ItLayout>
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(ItLayout::class);
    }

    /**
     * Perangkat asal jalur.
     *
     * @return BelongsTo<ItAsset>
     */
    public function fromAsset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'from_asset_id');
    }

    /**
     * Perangkat tujuan jalur.
     *
     * @return BelongsTo<ItAsset>
     */
    public function toAsset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'to_asset_id');
    }
}
