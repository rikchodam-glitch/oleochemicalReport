<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['code', 'name'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
