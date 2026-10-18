<?php

namespace App\Enums;

/**
 * Valeurs de `visibility` pour une offre.
 */
enum OfferVisibility: string
{
    case Ciblee = 'ciblee';
    case Publique = 'publique';

    public function label(): string
    {
        return match ($this) {
            self::Ciblee => 'Ciblée',
            self::Publique => 'Publique',
        };
    }
}
