<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['company_id', 'code', 'name'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
