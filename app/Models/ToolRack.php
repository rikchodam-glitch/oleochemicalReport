<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rak penyimpanan alat di bawah satu lokasi.
 */
class ToolRack extends Model
{
    protected $fillable = [
        'tool_location_id',
        'name',
        'description',
    ];

    /**
     * Lokasi pemilik rak.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ToolLocation::class, 'tool_location_id');
    }

    /**
     * Unit alat yang disimpan di rak ini.
     */
    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class, 'tool_rack_id')->orderBy('name')->orderBy('code');
    }

    /**
     * Cari rak berdasarkan nama.
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
