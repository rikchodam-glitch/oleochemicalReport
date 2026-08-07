<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmReading extends Model
{
    protected $table = 'cm_readings';

    protected $fillable = [
        'cm_equipment_id',
        'tanggal',
        'status',
        'ndev_motor', 'ndeh_motor', 'ndea_motor', 'temp_de_motor', 'temp_nde_motor',
        'dev_motor', 'deh_motor', 'dea_motor',
        'ndev_pompa', 'ndeh_pompa', 'ndea_pompa', 'temp_de_pompa', 'temp_nde_pompa',
        'dev_pompa', 'deh_pompa', 'dea_pompa',
        'ndev_screw', 'ndeh_screw', 'ndea_screw', 'temp_de_screw', 'temp_nde_screw',
        'dev_screw', 'deh_screw', 'dea_screw',
        'kondisi',
        'ampere', 'discharge_pressure', 'oil_level',
        'mech_seal', 'noise', 'coupling', 'safety', 'remark',
        'max_vibration', 'max_temp', 'analysis', 'data_no',
    ];

    protected function casts(): array
    {
        return [
            'tanggal'          => 'date:Y-m-d',
            'ndev_motor'       => 'decimal:2',
            'ndeh_motor'       => 'decimal:2',
            'ndea_motor'       => 'decimal:2',
            'temp_de_motor'    => 'decimal:2',
            'temp_nde_motor'   => 'decimal:2',
            'dev_motor'        => 'decimal:2',
            'deh_motor'        => 'decimal:2',
            'dea_motor'        => 'decimal:2',
            'ndev_pompa'       => 'decimal:2',
            'ndeh_pompa'       => 'decimal:2',
            'ndea_pompa'       => 'decimal:2',
            'temp_de_pompa'    => 'decimal:2',
            'temp_nde_pompa'   => 'decimal:2',
            'dev_pompa'        => 'decimal:2',
            'deh_pompa'        => 'decimal:2',
            'dea_pompa'        => 'decimal:2',
            'ndev_screw'       => 'decimal:2',
            'ndeh_screw'       => 'decimal:2',
            'ndea_screw'       => 'decimal:2',
            'temp_de_screw'    => 'decimal:2',
            'temp_nde_screw'   => 'decimal:2',
            'dev_screw'        => 'decimal:2',
            'deh_screw'        => 'decimal:2',
            'dea_screw'        => 'decimal:2',
            'ampere'           => 'decimal:2',
            'discharge_pressure' => 'decimal:2',
            'max_vibration'    => 'decimal:2',
            'max_temp'         => 'decimal:2',
            'data_no'          => 'integer',
        ];
    }

    /** @return BelongsTo<CmEquipment, CmReading> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CmEquipment::class, 'cm_equipment_id');
    }
}
