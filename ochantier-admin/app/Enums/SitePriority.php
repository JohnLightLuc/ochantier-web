<?php

namespace App\Enums;

enum SitePriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Normal = 'normal';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Haute',
            self::Medium => 'Moyenne',
            self::Normal => 'Normale',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::High => 'r',
            self::Medium => 'a',
            self::Normal => 'n',
        };
    }
}
