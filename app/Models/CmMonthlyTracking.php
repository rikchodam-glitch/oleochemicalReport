<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmMonthlyTracking extends Model
{
    protected $table = 'cm_monthly_tracking';

    protected $fillable = [
        'cm_equipment_id',
        'tahun',
        'bulan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
        ];
    }

    /** @return BelongsTo<CmEquipment, CmMonthlyTracking> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CmEquipment::class, 'cm_equipment_id');
    }
}
