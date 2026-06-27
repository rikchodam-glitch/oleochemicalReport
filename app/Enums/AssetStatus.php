<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Nonaktif',
            self::NeedsReview => 'Perlu Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'slate',
            self::NeedsReview => 'amber',
        };
    }
}
