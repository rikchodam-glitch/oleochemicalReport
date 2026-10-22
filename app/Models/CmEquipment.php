<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmEquipment extends Model
{
    protected $table = 'cm_equipment';

    protected $fillable = [
        'equipment_tag',
        'pt_location',
        'plant',
        'tipe_lubrikasi',
        'is_active',
        'in_master',
        'deactivated_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'in_master'      => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    /** @return HasMany<CmReading> */
    public function readings(): HasMany
    {
        return $this->hasMany(CmReading::class, 'cm_equipment_id');
    }

    /** @return HasMany<CmFinding> */
    public function findings(): HasMany
    {
        return $this->hasMany(CmFinding::class, 'cm_equipment_id');
    }

    /** @return HasMany<CmMonthlyTracking> */
    public function monthlyTrackings(): HasMany
    {
        return $this->hasMany(CmMonthlyTracking::class, 'cm_equipment_id');
    }

    /**
     * Relasi ke Asset (Asset Management).
     *
     * Mencocokkan cm_equipment.equipment_tag dengan assets.tech_ident_no
     * secara PERSIS (exact match). Tag berprefix "2-" (misal "2-6400P5")
     * TIDAK disambungkan ke asset Line 1 ("6400P5") — keduanya equipment
     * yang berbeda. Jika asset dengan tech_ident_no yang cocok ditambahkan
     * di Asset Management nanti, relasi ini otomatis menemukannya.
     *
     * CATATAN: Relasi belongsTo hanya 1:1. Karena bisa ada beberapa asset
     * dengan tech_ident_no yang sama (misal pompa + motor), relasi ini
     * mengembalikan yang pertama ditemukan. Untuk mengambil semua asset
     * terkait, gunakan method allAssets().
     *
     * @return BelongsTo<Asset, CmEquipment>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'equipment_tag', 'tech_ident_no');
    }

    /**
     * Scope untuk filter berdasarkan PT.
     */
    public function scopeByPt($query, $pt)
    {
        return $query->where('pt_location', $pt);
    }

    /**
     * Scope untuk filter berdasarkan plant.
     */
    public function scopeByPlant($query, $plant)
    {
        return $query->where('plant', $plant);
    }

    /**
     * Equipment yang masih dimonitoring.
     *
     * Tag yang dihapus dari sheet "Master Equipment" dinonaktifkan oleh
     * CmMasterEquipmentSyncService (histori reading/finding tetap disimpan),
     * sehingga daftar, kartu ringkasan, dan modal "belum diambil data" pada
     * halaman CM hanya boleh memakai scope ini.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Equipment yang sudah tidak dimonitoring (dihapus dari Master Equipment).
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

}