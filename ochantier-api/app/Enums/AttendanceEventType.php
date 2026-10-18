<?php

namespace App\Enums;

/**
 * Valeurs de `event_type` pour un pointage.
 */
enum AttendanceEventType: string
{
    case Arrivee = 'arrivee';
    case Depart = 'depart';

    public function label(): string
    {
        return match ($this) {
            self::Arrivee => 'Arrivée',
            self::Depart => 'Départ',
        };
    }
}
