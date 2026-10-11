<?php

namespace App\Enums;

enum SalesAgentAvailability: string
{
    case Available = 'available';
    case Partial = 'partial';
    case FieldMission = 'field_mission';
    case Leave = 'leave';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Partial => 'Partiellement disponible',
            self::FieldMission => 'En mission terrain',
            self::Leave => 'En congé',
            self::Unavailable => 'Indisponible',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'g',
            self::Partial => 'a',
            self::FieldMission => 'b',
            self::Leave => 'p',
            self::Unavailable => 'r',
        };
    }
}
