<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAiSuggestion extends Model
{
    protected $fillable = [
        'report_id',
        'suggestion_type',
        'suggested_area_id',
        'suggested_asset_id',
        'confidence',
        'reasoning',
        'accepted',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'accepted' => 'boolean',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function suggestedArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'suggested_area_id');
    }

    public function suggestedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'suggested_asset_id');
    }
}
