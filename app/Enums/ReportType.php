<?php

namespace App\Enums;

enum ReportType: string
{
    case EquipmentRepair = 'equipment_repair';
    case AreaWork = 'area_work';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::EquipmentRepair => 'Perbaikan Equipment',
            self::AreaWork => 'Pekerjaan Area',
            self::General => 'Umum',
        };
    }
}
