<?php

namespace App\Enums;

/**
 * Variable financière : acompte, commissions et seuil de progression.
 */
enum PricingVariable: string
{
    case X = 'X';
    case Y = 'Y';
    case Z = 'Z';
    case R = 'R';

    public function label(): string
    {
        return match ($this) {
            self::X => 'Acompte (X)',
            self::Y => 'Commission promoteur (Y)',
            self::Z => 'Commission ouvrier (Z)',
            self::R => 'Seuil de progression (R)',
        };
    }
}
