<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiUsageLog extends Model
{
    protected $fillable = [
        'provider_id',
        'report_id',
        'tokens_used',
        'request_type',
        'response_time_ms',
        'status',
        'error_message',
    ];

    // =========================================================
    // Relasi
    // =========================================================

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    // =========================================================
    // Scopes
    // =========================================================

    /**
     * Filter log dalam rentang N jam terakhir.
     *
     * @param Builder $query
     * @param int     $hours Jumlah jam ke belakang (default 24)
     * @return Builder
     */
    public function scopeLastHours(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Filter log dalam bulan berjalan (1 Januari s.d. hari ini).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }

    /**
     * Filter hanya log yang berhasil.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    /**
     * Filter hanya log yang error.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeError(Builder $query): Builder
    {
        return $query->where('status', 'error');
    }

    // =========================================================
    // Method helper agregasi
    // =========================================================

    /**
     * Ringkasan statistik per request_type dalam N jam terakhir.
     * Mengembalikan koleksi dengan kolom:
     *   request_type, total_calls, success_count, error_count,
     *   avg_tokens, total_tokens, avg_response_ms
     *
     * @param int $hours Rentang jam ke belakang (default 24)
     * @return Collection
     */
    public static function statsByRequestType(int $hours = 24): Collection
    {
        return static::query()
            ->lastHours($hours)
            ->select([
                'request_type',
                DB::raw('COUNT(*) as total_calls'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END) as error_count"),
                DB::raw('ROUND(AVG(tokens_used), 0) as avg_tokens'),
                DB::raw('SUM(tokens_used) as total_tokens'),
                DB::raw('ROUND(AVG(response_time_ms), 0) as avg_response_ms'),
            ])
            ->groupBy('request_type')
            ->orderByDesc('total_calls')
            ->get();
    }

    /**
     * Statistik penggunaan token harian per provider dalam N hari terakhir.
     * Mengembalikan koleksi dengan kolom:
     *   provider_id, tanggal, total_tokens, total_calls
     *
     * @param int $days Jumlah hari ke belakang (default 7)
     * @return Collection
     */
    public static function dailyTokensPerProvider(int $days = 7): Collection
    {
        return static::query()
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->select([
                'provider_id',
                DB::raw('DATE(created_at) as tanggal'),
                DB::raw('SUM(tokens_used) as total_tokens'),
                DB::raw('COUNT(*) as total_calls'),
            ])
            ->groupBy('provider_id', DB::raw('DATE(created_at)'))
            ->orderBy('tanggal')
            ->get();
    }

    /**
     * Statistik ringkas per provider dalam 24 jam terakhir.
     * Mengembalikan koleksi dengan kolom:
     *   provider_id, total_calls, success_count, error_count,
     *   total_tokens, avg_response_ms
     *
     * @return Collection
     */
    public static function statsPerProvider24h(): Collection
    {
        return static::query()
            ->lastHours(24)
            ->select([
                'provider_id',
                DB::raw('COUNT(*) as total_calls'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END) as error_count"),
                DB::raw('SUM(tokens_used) as total_tokens'),
                DB::raw('ROUND(AVG(response_time_ms), 0) as avg_response_ms'),
            ])
            ->groupBy('provider_id')
            ->get()
            ->keyBy('provider_id');
    }
}
