<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CmFinding extends Model
{
    protected $table = 'cm_findings';

    protected $fillable = [
        'cm_equipment_id',
        'cm_reading_id',
        'kode_finding',
        'severity',
        'kategori',
        'deskripsi',
        'analysis',
        'action',
        'status',
        'pic',
        'tanggal_temuan',
        'date_action',
        'foto_url',
        'foto_urls',
        'hari_open',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_temuan' => 'date:Y-m-d',
            'date_action'    => 'date:Y-m-d',
            'hari_open'      => 'integer',
            'foto_urls'      => 'json',
        ];
    }

    /** @return BelongsTo<CmEquipment, CmFinding> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CmEquipment::class, 'cm_equipment_id');
    }

    /** @return BelongsTo<CmReading, CmFinding> */
    public function reading(): BelongsTo
    {
        return $this->belongsTo(CmReading::class, 'cm_reading_id');
    }

    /**
     * Relasi ke Work Order yang berasal dari finding ini.
     * Satu finding bisa menghasilkan satu work order.
     *
     * @return HasOne<WorkOrder>
     */
    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class, 'linked_finding_id');
    }
}
