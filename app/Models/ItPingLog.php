<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItPingLog extends Model
{
    protected $fillable = [
        'it_asset_id',
        'status',
        'latency_ms',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /**
     * Perangkat IT yang menjadi pemilik log ping ini.
     *
     * @return BelongsTo<ItAsset, ItPingLog>
     */
    public function itAsset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class);
    }
}
