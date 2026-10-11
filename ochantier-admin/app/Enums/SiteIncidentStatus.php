<?php

namespace App\Enums;

enum SiteIncidentStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouvert',
            self::InProgress => 'En traitement',
            self::Resolved => 'Résolu',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'r',
            self::InProgress => 'b',
            self::Resolved => 'g',
        };
    }
}
