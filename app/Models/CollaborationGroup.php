<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * CollaborationGroup
 *
 * Kelompok kolaborasi: anggota yang otomatis ikut andil ketika salah satu
 * anggotanya membuat laporan (lihat App\Services\CollaborationService).
 *
 * Berbeda dari kolom technicians.group yang hanya penanda grup kerja teknisi,
 * tabel ini menyimpan daftar anggota yang bisa disesuaikan admin tanpa
 * mengubah data teknisi.
 */
class CollaborationGroup extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'auto_collaborate',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            'auto_collaborate' => 'boolean',
        ];
    }

    /**
     * Anggota kelompok.
     *
     * @return BelongsToMany<Technician>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            Technician::class,
            'collaboration_group_members',
            'collaboration_group_id',
            'technician_id'
        )->withPivot(['sort_order'])->withTimestamps();
    }

    /**
     * Scope: hanya kelompok yang aktif.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CollaborationGroup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<CollaborationGroup>
     */
    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: kelompok yang otomatis mengikutkan anggotanya pada laporan.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CollaborationGroup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<CollaborationGroup>
     */
    public function scopeOtomatis($query)
    {
        return $query->where('auto_collaborate', true);
    }
}
