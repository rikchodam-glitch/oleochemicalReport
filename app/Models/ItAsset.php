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
    /**
     * Daftar tipe perangkat IT yang dikenali sistem. Dipakai form perangkat IT
     * dan juga form cepat penambahan perangkat dari halaman Daftar IP, supaya
     * pilihannya selalu sama.
     */
    public const DEVICE_TYPES = [
        'PC', 'LAPTOP', 'PRINTER', 'CCTV', 'NVR', 'ACCESS_DOOR',
        'WIFI', 'NETWORK', 'SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED',
        'ACCESS_POINT', 'HUB', 'UPS', 'OTHER',
    ];

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

    /**
     * Daftar label => nilai tampilan untuk spesifikasi PC (monitor, CPU, RAM,
     * penyimpanan). Dipakai di halaman detail agar struktur nested spec tampil
     * rapi; kunci spec lama yang masih datar (mis. 'os') tetap diikutkan.
     *
     * @return array<string, string>
     */
    public function pcSpecItems(): array
    {
        $spec = $this->spec ?? [];
        $monitor = is_array($spec['monitor'] ?? null) ? $spec['monitor'] : [];
        $cpu = is_array($spec['cpu'] ?? null) ? $spec['cpu'] : [];
        $ram = is_array($cpu['ram'] ?? null) ? $cpu['ram'] : [];

        $items = [
            'Monitor Model'     => $monitor['model'] ?? null,
            'Monitor Serial'    => $monitor['serial_number'] ?? null,
            'CPU Model'         => $cpu['model'] ?? null,
            'CPU Serial'        => $cpu['serial_number'] ?? null,
            'Processor'         => $cpu['processor'] ?? null,
            'RAM Kapasitas'     => $ram['capacity'] ?? null,
            'RAM Jenis'         => $ram['type'] ?? null,
            'RAM Slot'          => $ram['slot'] ?? null,
            'RAM Speed'         => $ram['bandwidth'] ?? null,
            'Penyimpanan'       => $cpu['storage'] ?? null,
            'Kapasitas Disk'    => $cpu['storage_capacity'] ?? null,
        ];

        $items = array_filter($items, fn ($value) => $value !== null && $value !== '');

        // Sertakan kunci spec lama yang masih berbentuk nilai datar (bukan
        // array) agar tidak hilang saat data PC hasil import sebelumnya dibuka
        // lagi. Kunci terstruktur (monitor, cpu) sudah dihandle di atas.
        foreach ($spec as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $items[str_replace('_', ' ', (string) $key)] = $value;
        }

        return $items;
    }

    /**
     * Ringkasan satu baris spesifikasi PC untuk ditampilkan di kartu daftar.
     *
     * @return string
     */
    public function pcSpecSummary(): string
    {
        $spec = $this->spec ?? [];
        $monitor = is_array($spec['monitor'] ?? null) ? $spec['monitor'] : [];
        $cpu = is_array($spec['cpu'] ?? null) ? $spec['cpu'] : [];
        $ram = is_array($cpu['ram'] ?? null) ? $cpu['ram'] : [];

        $parts = [];

        if (!empty($monitor['model'])) {
            $parts[] = 'Monitor: ' . $monitor['model']
                . (!empty($monitor['serial_number']) ? ' (S/N ' . $monitor['serial_number'] . ')' : '');
        }

        if (!empty($cpu['model'])) {
            $parts[] = 'CPU: ' . $cpu['model']
                . (!empty($cpu['serial_number']) ? ' (S/N ' . $cpu['serial_number'] . ')' : '');
        } elseif (!empty($cpu['processor'])) {
            $parts[] = 'CPU: ' . $cpu['processor'];
        }

        if (!empty($ram['capacity'])) {
            $parts[] = 'RAM: ' . $ram['capacity']
                . (!empty($ram['type']) ? ' ' . $ram['type'] : '');
            if (!empty($ram['bandwidth'])) {
                $parts[count($parts) - 1] .= ' ' . $ram['bandwidth'];
            }
        }

        if (!empty($cpu['storage'])) {
            $parts[] = 'Penyimpanan: ' . $cpu['storage'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Daftar label => nilai untuk spefikasi CCTV (pointing, show/NVR, channel)
     * ditambah kunci spec datar lainnya (connect_to, pic, dst).
     *
     * @return array<string, string>
     */
    public function cctvSpecItems(): array
    {
        $spec = $this->spec ?? [];

        $items = [
            'Pointing' => $spec['pointing'] ?? null,
            'NVR (Show)' => $spec['show'] ?? null,
            'Channel' => $spec['channel'] ?? null,
        ];
        $items = array_filter($items, fn ($value) => $value !== null && $value !== '');

        $reserved = ['pointing', 'show', 'channel'];
        foreach ($spec as $key => $value) {
            if (in_array($key, $reserved, true) || is_array($value)) {
                continue;
            }
            $items[str_replace('_', ' ', (string) $key)] = $value;
        }

        return $items;
    }

    /**
     * Ringkasan satu baris spefikasi CCTV untuk kartu daftar.
     *
     * @return string
     */
    public function cctvSummary(): string
    {
        $spec = $this->spec ?? [];

        $parts = [];
        if (!empty($spec['show'])) {
            $parts[] = 'NVR: ' . $spec['show'];
        }
        if (!empty($spec['channel'])) {
            $parts[] = 'CH: ' . $spec['channel'];
        }
        if (!empty($spec['pointing'])) {
            $parts[] = 'Pointing: ' . $spec['pointing'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Daftar label => nilai spesifikasi untuk tampilan generik (mis. popup
     * denah). Menyesuaikan tipe perangkat: PC memakai pcSpecItems(), CCTV
     * memakai cctvSpecItems(), tipe lain memakai kunci spec datar.
     *
     * @return array<string, string>
     */
    public function specDisplayItems(): array
    {
        if ($this->device_type === 'PC') {
            return $this->pcSpecItems();
        }

        if ($this->device_type === 'CCTV') {
            return $this->cctvSpecItems();
        }

        $items = [];
        foreach (($this->spec ?? []) as $key => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }
            $items[ucwords(str_replace('_', ' ', (string) $key))] = $value;
        }

        return $items;
    }
}
