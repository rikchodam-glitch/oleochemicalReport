<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokasi penyimpanan alat (master khusus Management Tools).
 */
class ToolLocation extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * Tipe data kolom lokasi.
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
     * Rak yang berada di lokasi ini.
     */
    public function racks(): HasMany
    {
        return $this->hasMany(ToolRack::class)->orderBy('name');
    }

    /**
     * Batasi ke lokasi aktif.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Cari lokasi berdasarkan nama.
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
