<?php

namespace App\Enums;

/**
 * Valeurs de `kind` pour un entrepreneur.
 */
enum ContractorKind: string
{
    case Entreprise = 'entreprise';
    case Independant = 'independant';

    public function label(): string
    {
        return match ($this) {
            self::Entreprise => 'Entreprise',
            self::Independant => 'Indépendant',
        };
    }
}
