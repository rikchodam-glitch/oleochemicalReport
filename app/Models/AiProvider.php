<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class AiProvider extends Model
{
    protected $fillable = [
        'name',
        'provider_type',
        'api_key_encrypted',
        'model',
        'endpoint_url',
        'priority',
        'monthly_token_limit',
        'daily_token_limit',
        'tokens_used_today',
        'tokens_used_month',
        'request_count_24h',
        'last_used_at',
        'last_health_check',
        'status',
        'quota_limit_tokens_per_minute',
        'quota_remaining_tokens_per_minute',
        'quota_limit_requests_per_minute',
        'quota_remaining_requests_per_minute',
        'quota_checked_at',
        'cooldown_until',
        'quota_day',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at'      => 'datetime',
            'last_health_check' => 'datetime',
            'quota_checked_at'  => 'datetime',
            'cooldown_until'    => 'datetime',
            'quota_day'         => 'date',
        ];
    }

    /**
     * Keterangan kuota per menit menurut API provider.
     *
     * @return string|null
     */
    public function getApiQuotaTextAttribute(): ?string
    {
        if ($this->quota_remaining_tokens_per_minute === null) {
            return null;
        }

        return number_format((int) $this->quota_remaining_tokens_per_minute)
            . ' / ' . number_format((int) $this->quota_limit_tokens_per_minute) . ' token per menit (menurut API)';
    }

    // =========================================================
    // BUG 2 FIX — Accessor: dekripsi api_key_encrypted secara
    // otomatis. Jika nilai sudah plaintext (tidak ter-enkripsi),
    // fallback langsung kembalikan nilai asli agar tidak crash.
    // =========================================================
    /**
     * Ambil API key yang sudah didekripsi.
     * Akses via: $provider->api_key
     */
    public function getApiKeyAttribute(): string
    {
        $raw = $this->api_key_encrypted ?? '';

        if (empty($raw)) {
            return '';
        }

        try {
            // Coba dekripsi — jika berhasil, kembalikan plaintext
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            // Nilai bukan hasil enkripsi Laravel (sudah plaintext),
            // kembalikan langsung apa adanya
            return $raw;
        }
    }

    // =========================================================
    // Relasi
    // =========================================================
    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class, 'provider_id');
    }

    // =========================================================
    // Scopes
    // =========================================================
    public function scopeHealthy($query)
    {
        return $query->where('status', 'healthy');
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority');
    }

    // =========================================================
    // Computed attributes
    // =========================================================
    public function getDailyRemainingAttribute(): int
    {
        return max(0, $this->daily_token_limit - $this->tokens_used_today);
    }

    public function getMonthlyRemainingAttribute(): int
    {
        return max(0, $this->monthly_token_limit - $this->tokens_used_month);
    }

    public function getDailyUsagePercentAttribute(): float
    {
        if ($this->daily_token_limit <= 0) return 0;
        return round(($this->tokens_used_today / $this->daily_token_limit) * 100, 1);
    }

    public function getMonthlyUsagePercentAttribute(): float
    {
        if ($this->monthly_token_limit <= 0) return 0;
        return round(($this->tokens_used_month / $this->monthly_token_limit) * 100, 1);
    }
}
