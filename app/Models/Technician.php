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
            'area_ids' => 'array',
            'approved_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public const GROUPS = [
        'reguler' => 'Reguler',
        'grub_a' => 'Grub A',
        'grub_b' => 'Grub B',
        'grub_c' => 'Grub C',
    ];

    public const SECTIONS = [
        'mekanik' => 'Mekanik',
        'electrical' => 'Electrical',
        'it' => 'IT',
        'instrumentasi' => 'Instrumentasi',
        'sipil' => 'Sipil',
        'welding' => 'Welding',
        'general' => 'General',
        'lainnya' => 'Lainnya',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

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

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
