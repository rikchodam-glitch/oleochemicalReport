<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log transkripsi pesan suara Telegram.
 */
class VoiceTranscription extends Model
{
    public const STATUS_SUKSES = 'sukses';
    public const STATUS_GAGAL  = 'gagal';

    protected $fillable = [
        'technician_id',
        'chat_id',
        'duration_seconds',
        'model',
        'language',
        'confidence',
        'transcript',
        'latency_ms',
        'status',
        'error',
    ];

    /**
     * Tipe data kolom log.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'latency_ms'       => 'integer',
        ];
    }

    /**
     * Teknisi pemilik pesan suara.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
