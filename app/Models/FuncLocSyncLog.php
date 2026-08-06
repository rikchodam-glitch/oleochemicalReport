<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuncLocSyncLog extends Model
{
    /**
     * Log hasil sinkronisasi otomatis (FuncLocSyncService::execute()).
     */
    public const TYPE_AUTO_SYNC = 'auto_sync';

    /**
     * Log penautan satu asset secara manual dari halaman rekonsiliasi.
     */
    public const TYPE_MANUAL_LINK = 'manual_link';

    /**
     * Log penautan beberapa asset sekaligus ke satu FuncLoc yang sama.
     */
    public const TYPE_BULK_LINK = 'bulk_link';

    protected $fillable = [
        'executed_by',
        'type',
        'total_scanned',
        'node_created_count',
        'asset_linked_count',
        'asset_skipped_empty_count',
        'asset_missing_node_count',
        'detail_json',
    ];

    protected function casts(): array
    {
        return [
            'detail_json' => 'array',
        ];
    }

    /**
     * User yang menjalankan sinkronisasi atau aksi penautan ini.
     *
     * @return BelongsTo<User, FuncLocSyncLog>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * Batasi query hanya pada log dengan type tertentu (auto_sync,
     * manual_link, atau bulk_link).
     *
     * @param  Builder  $query
     * @param  string   $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
