<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok alat (mis. Bor Batrai) yang menaungi beberapa unit fisik.
 */
class ToolGroup extends Model
{
    protected $fillable = [
        'tool_type_id',
        'name',
        'description',
    ];

    /**
     * Jenis alat dari kelompok ini.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ToolType::class, 'tool_type_id');
    }

    /**
     * Unit alat dalam kelompok ini.
     */
    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class, 'tool_group_id');
    }

    /**
     * Cari kelompok berdasarkan nama.
     */
    public function scopeCari(Builder $query, ?string $teks): Builder
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return $query;
        }

        return $query->where('name', 'like', '%' . $teks . '%');
    }
}
