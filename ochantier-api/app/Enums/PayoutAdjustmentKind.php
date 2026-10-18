<?php

namespace App\Enums;

/**
 * Valeurs de `kind` pour un ajustement de reversement.
 */
enum PayoutAdjustmentKind: string
{
    case Retenue = 'retenue';
    case Bonus = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::Retenue => 'Retenue',
            self::Bonus => 'Bonus',
        };
    }
}
