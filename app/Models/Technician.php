<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'name',
        'nik',
        'department_id',
        'area_ids',
        'group',
        'section',
        'status',
        'approved_by',
        'approved_at',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'area_ids'       => 'array',
            'approved_at'    => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public const GROUPS = [
        'reguler'       => 'Reguler',
        'reguler_staff' => 'Reguler Staff',
        'grub_a'        => 'Grub A',
        'grub_b'        => 'Grub B',
        'grub_c'        => 'Grub C',
        'grub_d'        => 'Grub D',
    ];

    /**
     * Group pekerja shift (dipakai untuk tampilan Shift, pola rotasi, dan
     * penentuan target man hour berbasis shift). Jangan memakai
     * "GROUPS kecuali reguler" karena group non-shift ada dua.
     */
    public const GRUP_SHIFT = ['grub_a', 'grub_b', 'grub_c', 'grub_d'];

    /**
     * Group non-shift: reguler (Senin-Sabtu) dan reguler staff (Senin-Jumat).
     * Laporan kedua group ini masuk ke tampilan "Reguler" di halaman Laporan.
     */
    public const GRUP_NON_SHIFT = ['reguler', 'reguler_staff'];

    /**
     * Apakah group teknisi termasuk pekerja shift (Grub A-D).
     *
     * @param  string|null $grup Group teknisi
     * @return bool               True bila pekerja shift
     */
    public static function grupShift(?string $grup): bool
    {
        return in_array((string) $grup, self::GRUP_SHIFT, true);
    }

    public const SECTIONS = [
        'mekanik'   => 'Mekanik',
        'ei'        => 'EI',
        'it'        => 'IT',
        'sipil'     => 'Sipil',
        'fabrikasi' => 'Fabrikasi',
        'general'   => 'General',
        'lainnya'   => 'Lainnya',
        'cm_pm'     => 'CM/PM',
    ];

    // =========================================================
    // RELASI YANG SUDAH ADA
    // =========================================================

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Semua laporan milik teknisi ini (sebagai pemilik/penerima laporan).
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function assignedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_technician')
            ->withPivot(['note', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Kelompok kolaborasi yang diikuti teknisi ini.
     *
     * Dipakai CollaborationService untuk menentukan rekan yang otomatis ikut
     * andil ketika teknisi ini membuat laporan.
     *
     * @return BelongsToMany<CollaborationGroup>
     */
    public function collaborationGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            CollaborationGroup::class,
            'collaboration_group_members',
            'technician_id',
            'collaboration_group_id'
        )->withPivot(['sort_order'])->withTimestamps();
    }

    // =========================================================
    // RELASI BARU — KOLABORASI
    // =========================================================

    /**
     * Laporan-laporan yang dibuat oleh teknisi ini untuk rekan kolaborator.
     * Ini adalah laporan di mana teknisi ini bertindak sebagai pengirim (creator),
     * bukan sebagai pemilik laporan (technician_id).
     *
     * @return HasMany<Report>
     */
    public function createdReports(): HasMany
    {
        return $this->hasMany(Report::class, 'creator_id');
    }

    /**
     * Jadwal shift teknisi ini (hasil import jadwal shift).
     *
     * @return HasMany<TechnicianShift>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TechnicianShift::class);
    }

    // =========================================================
    // SCOPES
    // =========================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
