<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asset extends Model
{
    protected $fillable = [
        'equipment_no',
        'description',
        'tech_ident_no',
        'object_type',
        'functional_loc',
        'company_id',
        'department_id',
        'area_id',
        'sub_area_id',
        'manufacturer',
        'model_number',
        'construct_year',
        'status',
        'has_equipment_no',
        'data_source',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'has_equipment_no' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function subArea(): BelongsTo
    {
        return $this->belongsTo(SubArea::class);
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'asset_technician')
            ->withPivot(['note', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByEquipmentNo($query, $no)
    {
        return $query->where('equipment_no', $no);
    }
}
