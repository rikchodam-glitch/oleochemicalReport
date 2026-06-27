<?php

namespace App\Enums;

enum ProviderStatus: string
{
    case Healthy = 'healthy';
    case Exhausted = 'exhausted';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Sehat',
            self::Exhausted => 'Habis',
            self::Error => 'Error',
            self::Disabled => 'Nonaktif',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'green',
            self::Exhausted => 'red',
            self::Error => 'red',
            self::Disabled => 'slate',
        };
    }
}
