<?php

namespace App\Enums;

/**
 * Valeurs de `scope` pour un cycle de matching.
 */
enum MatchingCycleScope: string
{
    case Global = 'global';
    case Zone = 'zone';
    case Metier = 'metier';
    case Offre = 'offre';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::Zone => 'Zone',
            self::Metier => 'Métier',
            self::Offre => 'Offre',
        };
    }
}
