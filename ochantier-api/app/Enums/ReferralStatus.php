<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une invitation de parrainage.
 */
enum ReferralStatus: string
{
    case Envoyee = 'envoyee';
    case Inscrite = 'inscrite';

    public function label(): string
    {
        return match ($this) {
            self::Envoyee => 'Envoyée',
            self::Inscrite => 'Inscrit',
        };
    }
}
