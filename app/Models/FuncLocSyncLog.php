<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuncLocSyncLog extends Model
{
    protected $fillable = [
        'executed_by',
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
     * User yang menjalankan sinkronisasi ini.
     *
     * @return BelongsTo<User, FuncLocSyncLog>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
