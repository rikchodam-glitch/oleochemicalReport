<?php

namespace App\Enums;

enum ImportAction: string
{
    case Replace = 'replace';
    case KeepFlag = 'keep_flag';
    case Cancel = 'cancel';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Replace => 'Ganti',
            self::KeepFlag => 'Tandai Review',
            self::Cancel => 'Batalkan',
            self::Skip => 'Lewati',
        };
    }
}
