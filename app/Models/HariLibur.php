<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hari libur nasional / perusahaan.
 *
 * Dipakai kalender teknisi dan perhitungan man hour: setiap tanggal yang
 * terdaftar di sini diperlakukan sebagai hari libur untuk SEMUA grup, sehingga
 * target menit 0 dan seluruh jam yang dilaporkan pada tanggal itu dihitung
 * lembur (diputuskan user 15/10/2026).
 */
class HariLibur extends Model
{
    protected $table = 'hari_libur';

    protected $fillable = [
        'tanggal',
        'nama',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    /**
     * Scope: filter rentang tanggal (inklusif).
     *
     * Batas memakai jam 00:00:00 dan 23:59:59 supaya tetap benar baik di MySQL
     * (kolom DATE) maupun SQLite (kolom date tersimpan sebagai datetime).
     *
     * @param  Builder $query   Query builder
     * @param  string  $mulai   Tanggal awal Y-m-d
     * @param  string  $selesai Tanggal akhir Y-m-d
     * @return Builder          Query builder
     */
    public function scopeAntara(Builder $query, string $mulai, string $selesai): Builder
    {
        return $query->whereBetween('tanggal', [$mulai . ' 00:00:00', $selesai . ' 23:59:59']);
    }

    /**
     * Peta hari libur (tanggal Y-m-d => nama) untuk satu rentang tanggal.
     *
     * Dipakai kalender teknisi dan JamKerjaService agar pemuatan hari libur
     * cukup satu kali per perhitungan.
     *
     * @param  string $mulai   Tanggal awal Y-m-d
     * @param  string $selesai Tanggal akhir Y-m-d
     * @return array<string, string> Peta tanggal => nama
     */
    public static function petaRentang(string $mulai, string $selesai): array
    {
        return static::query()
            ->antara($mulai, $selesai)
            ->orderBy('tanggal')
            ->get(['tanggal', 'nama'])
            ->mapWithKeys(fn (self $hari) => [$hari->tanggal->toDateString() => (string) $hari->nama])
            ->all();
    }
}
