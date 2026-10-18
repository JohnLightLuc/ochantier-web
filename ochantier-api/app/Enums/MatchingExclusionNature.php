<?php

namespace App\Enums;

/**
 * Valeurs de `nature` pour une exclusion de matching.
 */
enum MatchingExclusionNature: string
{
    case Reglementaire = 'reglementaire';
    case Operationnelle = 'operationnelle';
    case Manuelle = 'manuelle';

    public function label(): string
    {
        return match ($this) {
            self::Reglementaire => 'Réglementaire',
            self::Operationnelle => 'Opérationnelle',
            self::Manuelle => 'Manuelle',
        };
    }
}
