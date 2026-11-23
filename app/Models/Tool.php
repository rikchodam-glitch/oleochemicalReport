<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Satu unit fisik alat.
 *
 * Nama boleh sama untuk beberapa unit (mis. Bor Batrai), tetapi kode wajib
 * unik per unit (BRBT001, BRBT002).
 */
class Tool extends Model
{
    public const STATUS_TERSEDIA = 'tersedia';
    public const STATUS_DIPAKAI  = 'dipakai';
    public const STATUS_RUSAK    = 'rusak';

    /**
     * Label status untuk tampilan.
     */
    public const STATUS = [
        self::STATUS_TERSEDIA => 'Tersedia',
        self::STATUS_DIPAKAI  => 'Dipakai',
        self::STATUS_RUSAK    => 'Rusak',
    ];

    protected $fillable = [
        'tool_rack_id',
        'tool_group_id',
        'name',
        'code',
        'photo_path',
        'status',
        'note',
    ];

    /**
     * Rak tempat alat disimpan.
     */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(ToolRack::class, 'tool_rack_id');
    }

    /**
     * Kelompok alat (mis. Bor Batrai).
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ToolGroup::class, 'tool_group_id');
    }

    /**
     * Seluruh riwayat pinjaman alat ini.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(ToolLoan::class);
    }

    /**
     * Pinjaman yang belum dikembalikan (bila ada).
     */
    public function pinjamanAktif(): HasOne
    {
        return $this->hasOne(ToolLoan::class)
            ->whereNull('returned_at')
            ->orderByDesc('id');
    }

    /**
     * Pinjaman terakhir, baik yang masih aktif maupun sudah dikembalikan.
     */
    public function pinjamanTerakhir(): HasOne
    {
        return $this->hasOne(ToolLoan::class)->orderByDesc('id');
    }

    /**
     * URL foto alat untuk ditampilkan di halaman.
     */
    public function getFotoUrlAttribute(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    /**
     * Label status alat.
     */
    public function statusLabel(): string
    {
        return self::STATUS[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Cari alat berdasarkan nama atau kode.
     */
    public function scopeCari(Builder $query, ?string $teks): Builder
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($teks): void {
            $sub->where('name', 'like', '%' . $teks . '%')
                ->orWhere('code', 'like', '%' . $teks . '%');
        });
    }

    /**
     * Batasi ke alat berstatus tersedia.
     */
    public function scopeTersedia(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TERSEDIA);
    }

    /**
     * Hapus foto alat bersama barisnya.
     *
     * Kegagalan hapus berkas hanya dicatat supaya penghapusan data tetap
     * berhasil. Riwayat pinjaman menyimpan salinan nama/kode sehingga tetap
     * terbaca setelah unit dihapus.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::deleting(function (Tool $alat): void {
            if ($alat->photo_path === null || $alat->photo_path === '') {
                return;
            }

            try {
                Storage::disk('public')->delete($alat->photo_path);
            } catch (\Throwable $galat) {
                Log::warning('Tool: gagal menghapus foto alat', [
                    'id'    => $alat->id,
                    'foto'  => $alat->photo_path,
                    'error' => $galat->getMessage(),
                ]);
            }
        });
    }
}
