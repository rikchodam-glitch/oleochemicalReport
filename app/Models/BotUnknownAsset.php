<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotUnknownAsset extends Model
{
    protected $fillable = [
        'report_id',
        'keyword_mentioned',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
