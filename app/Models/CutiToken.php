<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token tautan form cuti yang dibuat bot Telegram.
 *
 * Token asli tidak disimpan; hanya hash SHA-256. Token sekali pakai, berumur
 * pendek, dan diikat ke teknisi/telegram_id agar form tidak memerlukan login
 * penuh ke panel admin.
 */
class CutiToken extends Model
{
    /**
     * Kolom yang boleh diisi massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'technician_id',
        'token_hash',
        'telegram_id',
        'alasan',
        'expires_at',
        'opened_at',
        'used_at',
    ];

    /**
     * Cast kolom waktu.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'opened_at'  => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    /**
     * Teknisi pemilik token.
     *
     * @return BelongsTo<Technician, CutiToken>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Apakah token masih bisa dipakai.
     *
     * @return bool
     */
    public function masihBerlaku(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
