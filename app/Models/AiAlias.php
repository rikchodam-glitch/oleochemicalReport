<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAlias extends Model
{
    protected $fillable = [
        'alias_text',
        'asset_id',
        'area_id',
        'technician_id',
        'source',
        'confidence',
        'usage_count',
        'status',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'usage_count' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
