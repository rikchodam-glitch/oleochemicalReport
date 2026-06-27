<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetImportLog extends Model
{
    protected $fillable = [
        'filename',
        'imported_by',
        'total_rows',
        'success_count',
        'duplicate_count',
        'no_equip_no_count',
        'error_count',
        'action_taken',
        'detail_json',
    ];

    protected function casts(): array
    {
        return [
            'detail_json' => 'array',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
