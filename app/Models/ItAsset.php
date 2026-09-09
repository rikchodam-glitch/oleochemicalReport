<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ItAsset extends Model
{
    /**
     * Device type yang mendukung fitur CCTV Snapshot (Sesi 7).
     * Dipakai oleh view detail untuk menampilkan/menyembunyikan panel
     * snapshot, dan oleh controller untuk validasi sebelum capture.
     *
     * @var array<int, string>
     */
    public const SNAPSHOT_CAPABLE_TYPES = ['CCTV', 'NVR'];

    protected $fillable = [
        'device_type',
        'tag_name',
        'name',
        'brand',
        'model',
        'serial_number',
        'ip_address',
        'plant',
        'department',
        'location',
        'application',
        'status',
        'spec',
        'last_ping_status',
        'last_ping_latency',
        'last_ping_at',
        'last_snapshot_path',
        'last_snapshot_at',
        'snapshot_url',
        'snapshot_username',
        'snapshot_password',
        'last_snapshot_error',
        'data_source',
        'imported_at',
        // Kolom koordinat lokasi untuk fitur Network Map.
        'latitude',
        'longitude',
        'location_set_at',
    ];

    /**
     * snapshot_password TIDAK PERNAH dikirim ke frontend (mis. lewat
     * ItAsset::toArray() pada endpoint JSON). Password hanya dipakai
     * secara internal oleh CctvSnapshotService.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'snapshot_password',
    ];

    protected function casts(): array
    {
        return [
            'spec'               => 'array',
            'last_ping_at'       => 'datetime',
            'last_snapshot_at'   => 'datetime',
            'imported_at'        => 'datetime',
            'location_set_at'    => 'datetime',
            'latitude'           => 'float',
            'longitude'          => 'float',
            // Dienkripsi otomatis oleh Laravel (APP_KEY) saat disimpan dan
            // didekripsi saat dibaca lewat Eloquent — tidak pernah plaintext
            // di kolom DB. Lihat CctvSnapshotService::resolveCredentials().
            'snapshot_password'  => 'encrypted',
        ];
    }

    /**
     * Riwayat ping untuk perangkat IT ini.
     *
     * @return HasMany<ItPingLog>
     */
    public function pingLogs(): HasMany
    {
        return $this->hasMany(ItPingLog::class);
    }

    /**
     * Jalur (link) yang berangkat dari perangkat ini (menjadi from_asset).
     *
     * @return HasMany<NetworkLink>
     */
    public function linksFrom(): HasMany
    {
        return $this->hasMany(NetworkLink::class, 'from_asset_id');
    }

    /**
     * Jalur (link) yang menuju ke perangkat ini (menjadi to_asset).
     *
     * @return HasMany<NetworkLink>
     */
    public function linksTo(): HasMany
    {
        return $this->hasMany(NetworkLink::class, 'to_asset_id');
    }

    /**
     * Posisi perangkat ini pada sebuah denah (layout). HasOne karena satu
     * perangkat hanya boleh ditempatkan di satu layout (UNIQUE it_asset_id).
     *
     * @return HasOne<ItLayoutItem>
     */
    public function layoutItem(): HasOne
    {
        return $this->hasOne(ItLayoutItem::class, 'it_asset_id');
    }

    /**
     * Scope perangkat dengan status active.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope filter perangkat berdasarkan device_type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('device_type', $type);
    }

    /**
     * Apakah device_type ini mendukung fitur CCTV Snapshot.
     *
     * @return bool
     */
    public function supportsSnapshot(): bool
    {
        return in_array($this->device_type, self::SNAPSHOT_CAPABLE_TYPES, true);
    }

    /**
     * Label tampilan device_type (mis. 'CCTV', 'Access Door').
     *
     * @return string
     */
    public function deviceLabel(): string
    {
        $labels = [
            'PC'          => 'PC',
            'LAPTOP'      => 'Laptop',
            'PRINTER'     => 'Printer',
            'CCTV'        => 'CCTV',
            'NVR'         => 'NVR',
            'ACCESS_DOOR' => 'Access Door',
            'WIFI'                  => 'WiFi',
            'NETWORK'               => 'Network',
            'SWITCH_MANAGED'        => 'Switch Managed',
            'SWITCH_POE_UNMANAGED'  => 'Switch PoE Unmanaged',
            'ACCESS_POINT'          => 'Access Point',
            'HUB'                   => 'HUB',
            'UPS'                   => 'UPS',
            'OTHER'                 => 'Lainnya',
        ];

        return $labels[$this->device_type] ?? $this->device_type ?? 'Perangkat';
    }

    /**
     * Nama tampilan perangkat: tag_name bila ada, jika tidak name, jika
     * tidak keduanya maka '#' + id.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->tag_name ?: ($this->name ?: ('#' . $this->id));
    }
}
