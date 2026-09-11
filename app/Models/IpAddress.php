<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpAddress extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_RESERVED = 'reserved';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ip_network_id',
        'address',
        'status',
        'it_asset_id',
        'notes',
    ];

    /**
     * Subnet tempat IP ini berada.
     *
     * @return BelongsTo<IpNetwork, IpAddress>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(IpNetwork::class, 'ip_network_id');
    }

    /**
     * Perangkat IT yang memakai IP ini (null bila masih available/reserved).
     *
     * @return BelongsTo<ItAsset, IpAddress>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'it_asset_id');
    }

    /**
     * Filter hanya IP yang masih tersedia untuk dipakai.
     *
     * @param  Builder<IpAddress>  $query
     * @return Builder<IpAddress>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    /**
     * Apakah status sekarang menandakan IP sudah dipakai perangkat.
     *
     * @return bool
     */
    public function isAssigned(): bool
    {
        return $this->status === self::STATUS_ASSIGNED;
    }
}
