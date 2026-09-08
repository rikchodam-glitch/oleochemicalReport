<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItLayoutItem extends Model
{
    /**
     * Kolom yang boleh diisi massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'layout_id',
        'it_asset_id',
        'x',
        'y',
        'is_locked',
    ];

    /**
     * Casting tipe data.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x'         => 'float',
            'y'         => 'float',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Layout (denah) tempat item diletakkan.
     *
     * @return BelongsTo<ItLayout>
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(ItLayout::class);
    }

    /**
     * Perangkat IT yang ditandai posisinya.
     *
     * @return BelongsTo<ItAsset>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'it_asset_id');
    }
}
