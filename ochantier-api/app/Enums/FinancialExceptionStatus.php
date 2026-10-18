<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une dérogation financière.
 */
enum FinancialExceptionStatus: string
{
    case Demandee = 'demandee';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';
    case Expiree = 'expiree';

    public function label(): string
    {
        return match ($this) {
            self::Demandee => 'Demandée',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
            self::Expiree => 'Expirée',
        };
    }
}
