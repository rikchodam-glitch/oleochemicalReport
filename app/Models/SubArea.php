<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubArea extends Model
{
    protected $fillable = ['area_id', 'code', 'name', 'funcloc_id'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * FuncLoc L3 yang merepresentasikan sub-area ini.
     */
    public function functionalLocation(): BelongsTo
    {
        return $this->belongsTo(FunctionalLocation::class, 'funcloc_id');
    }
}
