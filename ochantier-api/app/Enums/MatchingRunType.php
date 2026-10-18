<?php

namespace App\Enums;

/**
 * Valeurs de `run_type` pour une exécution de matching.
 */
enum MatchingRunType: string
{
    case Initial = 'initial';
    case Relance = 'relance';
    case Remplacement = 'remplacement';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial',
            self::Relance => 'Relance',
            self::Remplacement => 'Remplacement',
        };
    }
}
