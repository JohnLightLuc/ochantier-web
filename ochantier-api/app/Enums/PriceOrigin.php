<?php

namespace App\Enums;

/**
 * Valeurs de `price_origin` pour une offre.
 */
enum PriceOrigin: string
{
    case Ouvrier = 'ouvrier';
    case Promoteur = 'promoteur';

    public function label(): string
    {
        return match ($this) {
            self::Ouvrier => 'Ouvrier',
            self::Promoteur => 'Promoteur',
        };
    }
}
