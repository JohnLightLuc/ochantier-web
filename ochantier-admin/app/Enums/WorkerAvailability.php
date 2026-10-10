<?php

namespace App\Enums;

enum WorkerAvailability: string
{
    case Available = 'available';
    case PreAssigned = 'pre_assigned';
    case Assigned = 'assigned';
    case OnMission = 'on_mission';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::PreAssigned => 'Préaffecté',
            self::Assigned => 'Affecté',
            self::OnMission => 'En mission',
            self::Unavailable => 'Indisponible',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'g',
            self::PreAssigned => 'b',
            self::Assigned => 'p',
            self::OnMission => 'a',
            self::Unavailable => 'r',
        };
    }
}
