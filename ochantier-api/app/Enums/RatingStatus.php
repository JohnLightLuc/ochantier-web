<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une évaluation.
 */
enum RatingStatus: string
{
    case Brouillon = 'brouillon';
    case Publie = 'publie';
    case Masque = 'masque';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Publie => 'Publié',
            self::Masque => 'Masqué',
        };
    }
}
