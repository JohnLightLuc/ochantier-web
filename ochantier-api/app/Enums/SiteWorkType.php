<?php

namespace App\Enums;

/**
 * Nature des travaux d’un chantier (« Type de travaux »).
 */
enum SiteWorkType: string
{
    case ConstructionNeuve = 'construction_neuve';
    case Renovation = 'renovation';
    case Extension = 'extension';
    case Finitions = 'finitions';

    public function label(): string
    {
        return match ($this) {
            self::ConstructionNeuve => 'Construction neuve',
            self::Renovation => 'Rénovation',
            self::Extension => 'Extension',
            self::Finitions => 'Finitions',
        };
    }
}
