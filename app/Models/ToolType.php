<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master jenis alat (kategori bebas buatan administrator).
 */
class ToolType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * Tipe data kolom jenis.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kelompok alat yang memakai jenis ini.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(ToolGroup::class)->orderBy('name');
    }

    /**
     * Batasi ke jenis aktif.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Cari jenis berdasarkan nama.
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
