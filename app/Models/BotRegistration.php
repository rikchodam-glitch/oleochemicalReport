<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotRegistration extends Model
{
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'name',
        'nik',
        'requested_at',
        'status',
        'processed_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Teknisi yang dibuat dari pendaftaran ini (dicocokkan via telegram_id).
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'telegram_id', 'telegram_id');
    }
}
