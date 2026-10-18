<?php

namespace App\Enums;

/**
 * Valeurs de `phase` pour une indisponibilité.
 */
enum UnavailabilityRequestPhase: string
{
    case AvantDemarrage = 'avant_demarrage';
    case EnExecution = 'en_execution';

    public function label(): string
    {
        return match ($this) {
            self::AvantDemarrage => 'Avant démarrage',
            self::EnExecution => 'En exécution',
        };
    }
}
