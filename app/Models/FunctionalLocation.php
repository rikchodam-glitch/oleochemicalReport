<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunctionalLocation extends Model
{
    // Konstanta level agar tidak ada angka ajaib tersebar di kode
    public const LEVEL_SITE       = 0;
    public const LEVEL_DEPARTMENT = 1;
    public const LEVEL_AREA       = 2;
    public const LEVEL_SECTION    = 3;

    protected $fillable = [
        'code',
        'segment',
        'name',
        'level',
        'parent_id',
        'company_id',
        'department_id',
        'area_id',
        'sub_area_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level'     => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // =========================================================
    // RELASI — hierarki self-referential
    // =========================================================

    /**
     * Node induk (satu level di atas).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FunctionalLocation::class, 'parent_id');
    }

    /**
     * Node-node anak langsung (satu level di bawah).
     */
    public function children(): HasMany
    {
        return $this->hasMany(FunctionalLocation::class, 'parent_id');
    }

    /**
     * Semua keturunan secara rekursif (eager-loadable via eager loading manual
     * atau package staudenmeir/laravel-adjacency-list jika dibutuhkan).
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    // =========================================================
    // RELASI — master data lama
    // =========================================================

    /**
     * @return BelongsTo<Company, FunctionalLocation>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Department, FunctionalLocation>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Area, FunctionalLocation>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<SubArea, FunctionalLocation>
     */
    public function subArea(): BelongsTo
    {
        return $this->belongsTo(SubArea::class);
    }

    // =========================================================
    // RELASI — entitas yang menggunakan FuncLoc ini
    // =========================================================

    /**
     * @return HasMany<Asset>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'funcloc_id');
    }

    /**
     * @return HasMany<Report>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'funcloc_id');
    }

    // =========================================================
    // SCOPES
    // =========================================================

    /**
     * Hanya node yang aktif.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Filter berdasarkan level hierarki.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $level
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Hanya anak langsung dari parent tertentu.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $parentId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnderParent($query, int $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    // =========================================================
    // HELPER
    // =========================================================

    /**
     * Kembalikan label level dalam Bahasa Indonesia.
     *
     * @return string
     */
    public function getLevelLabelAttribute(): string
    {
        return match ($this->level) {
            self::LEVEL_SITE       => 'Site',
            self::LEVEL_DEPARTMENT => 'Departemen',
            self::LEVEL_AREA       => 'Area',
            self::LEVEL_SECTION    => 'Section',
            default                => 'Tidak Diketahui',
        };
    }

    /**
     * Bangun kode penuh (full path) dari kode parent + segment baru.
     * Dipakai saat membuat node baru dari form admin.
     *
     * @param  FunctionalLocation|null  $parent
     * @param  string                   $segment
     * @return string
     */
    public static function buildCode(?self $parent, string $segment): string
    {
        if ($parent === null) {
            return strtoupper(trim($segment));
        }

        return $parent->code . '-' . strtoupper(trim($segment));
    }

    /**
     * Ambil semua ancestor dari node ini, diurutkan dari root ke node saat ini.
     * Berguna untuk menampilkan breadcrumb hierarki.
     *
     * @return Collection<int, FunctionalLocation>
     */
    public function getAncestors(): Collection
    {
        $ancestors = new Collection();
        $current   = $this->parent;

        while ($current !== null) {
            $ancestors->prepend($current);
            $current = $current->parent;
        }

        return $ancestors;
    }
}
