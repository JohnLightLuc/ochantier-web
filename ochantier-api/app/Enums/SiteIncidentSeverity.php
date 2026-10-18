<?php

namespace App\Enums;

/**
 * Valeurs de `severity` pour un incident de chantier.
 */
enum SiteIncidentSeverity: string
{
    case Mineure = 'mineure';
    case Moderee = 'moderee';
    case Grave = 'grave';
    case Critique = 'critique';

    public function label(): string
    {
        return match ($this) {
            self::Mineure => 'Mineure',
            self::Moderee => 'Modérée',
            self::Grave => 'Grave',
            self::Critique => 'Critique',
        };
    }
}
