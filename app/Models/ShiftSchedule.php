<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Riwayat import jadwal shift teknisi (satu baris per file yang diimport).
 */
class ShiftSchedule extends Model
{
    protected $fillable = [
        'periode_mulai',
        'periode_selesai',
        'nama_file',
        'jumlah_baris',
        'jumlah_terpetakan',
        'jumlah_tidak_cocok',
        'detail_tidak_cocok',
        'diupload_oleh',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai'      => 'date',
            'periode_selesai'    => 'date',
            'jumlah_baris'       => 'integer',
            'jumlah_terpetakan'  => 'integer',
            'jumlah_tidak_cocok' => 'integer',
            'detail_tidak_cocok' => 'array',
        ];
    }

    /**
     * Semua baris shift yang dihasilkan import ini.
     *
     * @return HasMany<TechnicianShift>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TechnicianShift::class);
    }

    /**
     * User yang mengunggah file jadwal.
     *
     * @return BelongsTo<User, ShiftSchedule>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diupload_oleh');
    }

    /**
     * Label periode jadwal, mis. "26/09/2026 - 25/10/2026".
     *
     * @return string|null  Label periode atau null bila tanggal belum terbaca
     */
    public function getPeriodeLabelAttribute(): ?string
    {
        if ($this->periode_mulai === null || $this->periode_selesai === null) {
            return null;
        }

        return $this->periode_mulai->format('d/m/Y') . ' - ' . $this->periode_selesai->format('d/m/Y');
    }
}
