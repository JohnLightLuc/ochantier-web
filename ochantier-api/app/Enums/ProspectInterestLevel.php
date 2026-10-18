<?php

namespace App\Enums;

/**
 * Valeurs de `interest_level` pour un prospect.
 */
enum ProspectInterestLevel: string
{
    case Faible = 'faible';
    case Moyen = 'moyen';
    case Fort = 'fort';

    public function label(): string
    {
        return match ($this) {
            self::Faible => 'Faible',
            self::Moyen => 'Moyen',
            self::Fort => 'Fort',
        };
    }
}
