<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpNetwork extends Model
{
    /**
     * Batas VLAN yang valid untuk konvensi network 192.168.[vlan].0 (octet
     * ketiga maksimal 255).
     */
    public const VLAN_MIN = 1;
    public const VLAN_MAX = 255;

    /**
     * CIDR minimum/agar network 192.168.[vlan].0 tetap sejajar (aligned).
     * CIDR kecil (<24) akan menggeser network ke blok lain.
     */
    public const CIDR_MIN = 24;
    public const CIDR_MAX = 30;

    /**
     * Maksimal jumlah host usable per subnet yang boleh digenerate (mencegah
     * insert ribuan baris sekaligus). /22 = 1022 host masih dalam batas.
     */
    public const MAX_HOSTS = 4096;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'vlan',
        'cidr',
        'network',
        'netmask',
        'broadcast',
        'name',
        'description',
    ];

    /**
     * Daftar alamat IP milik subnet ini (hasil generate).
     *
     * @return HasMany<IpAddress>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * Notasi prefix: network/cidr (mis. 192.168.41.0/24).
     *
     * @return string
     */
    public function prefix(): string
    {
        return $this->network . '/' . $this->cidr;
    }

    /**
     * Label ringkas untuk ditampilkan di daftar: VLAN 41 · 192.168.41.0/24.
     *
     * @return string
     */
    public function label(): string
    {
        return 'VLAN ' . $this->vlan . ' · ' . $this->prefix();
    }
}
