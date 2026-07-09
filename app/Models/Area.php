<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = ['department_id', 'code', 'name', 'funcloc_id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function subAreas(): HasMany
    {
        return $this->hasMany(SubArea::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * FuncLoc L2 yang merepresentasikan area ini.
     */
    public function functionalLocation(): BelongsTo
    {
        return $this->belongsTo(FunctionalLocation::class, 'funcloc_id');
    }

    public function getFullNameAttribute(): string
    {
        return $this->code . ' — ' . $this->name;
    }
}
