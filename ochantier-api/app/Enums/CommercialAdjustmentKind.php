<?php

namespace App\Enums;

/**
 * Valeurs de `kind` pour une prime ou retenue de commercial.
 */
enum CommercialAdjustmentKind: string
{
    case Prime = 'prime';
    case Retenue = 'retenue';

    public function label(): string
    {
        return match ($this) {
            self::Prime => 'Prime',
            self::Retenue => 'Retenue',
        };
    }
}
