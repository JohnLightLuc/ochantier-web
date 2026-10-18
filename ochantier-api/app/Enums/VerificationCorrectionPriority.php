<?php

namespace App\Enums;

/**
 * Valeurs de `priority` pour une correction demandée.
 */
enum VerificationCorrectionPriority: string
{
    case Basse = 'basse';
    case Normale = 'normale';
    case Haute = 'haute';

    public function label(): string
    {
        return match ($this) {
            self::Basse => 'Basse',
            self::Normale => 'Normale',
            self::Haute => 'Haute',
        };
    }
}
