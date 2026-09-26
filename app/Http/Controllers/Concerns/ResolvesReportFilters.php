<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * ResolvesReportFilters
 *
 * Helper bersama untuk halaman yang menampilkan daftar laporan (halaman detail
 * lokasi dan halaman detail equipment) supaya aturan filter tanggalnya sama:
 *   - satu hari bila parameter `tanggal` diisi;
 *   - rentang bila `tanggal` kosong dan `date_from`/`date_to` diisi;
 *   - tanpa batas tanggal bila semuanya kosong.
 */
trait ResolvesReportFilters
{
    /**
     * Ubah nilai tanggal dari query string menjadi format Y-m-d.
     *
     * Nilai kosong atau tidak valid dikembalikan sebagai null supaya filter
     * yang salah tidak memicu error halaman.
     *
     * @param  mixed  $nilai  Nilai mentah dari query string
     * @return string|null
     */
    protected function tanggalValid(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $nilai)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Terapkan filter tanggal (satu hari atau rentang) ke query laporan.
     *
     * @param  Builder      $query    Query laporan
     * @param  string|null  $tanggal  Tanggal tunggal (Y-m-d), kosong = tidak dipakai
     * @param  string|null  $dari     Awal rentang (Y-m-d)
     * @param  string|null  $sampai   Akhir rentang (Y-m-d)
     * @return Builder  Query dengan filter tanggal
     */
    protected function terapkanFilterTanggal(Builder $query, ?string $tanggal, ?string $dari, ?string $sampai): Builder
    {
        return $query
            ->when($tanggal !== null, fn (Builder $q) => $q->whereDate('report_date', $tanggal))
            ->when(
                $tanggal === null && $dari !== null,
                fn (Builder $q) => $q->whereDate('report_date', '>=', $dari)
            )
            ->when(
                $tanggal === null && $sampai !== null,
                fn (Builder $q) => $q->whereDate('report_date', '<=', $sampai)
            );
    }
}
