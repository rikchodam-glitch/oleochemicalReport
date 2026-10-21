<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token form laporan sekali pakai yang dibuat bot Telegram.
 */
class ReportToken extends Model
{
    protected $fillable = [
        'technician_id',
        'token_hash',
        'telegram_id',
        'description',
        'expires_at',
        'opened_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'opened_at'  => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function masihBerlaku(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
