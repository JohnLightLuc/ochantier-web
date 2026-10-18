<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une intégration.
 */
enum IntegrationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case EnErreur = 'en_erreur';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::EnErreur => 'En erreur',
        };
    }
}
