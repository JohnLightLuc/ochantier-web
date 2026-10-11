<?php

namespace App\Enums;

enum SiteIncidentSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Faible',
            self::Medium => 'Moyenne',
            self::High => 'Élevée',
            self::Critical => 'Critique',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'n',
            self::Medium => 'a',
            self::High => 'o',
            self::Critical => 'r',
        };
    }
}
