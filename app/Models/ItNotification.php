<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItNotification extends Model
{
    protected $fillable = [
        'it_asset_id',
        'type',
        'message',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Perangkat IT terkait notifikasi ini.
     *
     * @return BelongsTo<ItAsset, ItNotification>
     */
    public function itAsset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class);
    }

    /**
     * Scope notifikasi yang belum dibaca.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
