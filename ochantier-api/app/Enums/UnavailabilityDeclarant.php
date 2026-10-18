<?php

namespace App\Enums;

/**
 * Valeurs de `declared_by_type` pour une indisponibilité.
 */
enum UnavailabilityDeclarant: string
{
    case Promoteur = 'promoteur';
    case Commercial = 'commercial';
    case Admin = 'admin';
    case Ouvrier = 'ouvrier';

    public function label(): string
    {
        return match ($this) {
            self::Promoteur => 'Promoteur',
            self::Commercial => 'Commercial',
            self::Admin => 'Administrateur',
            self::Ouvrier => 'Ouvrier',
        };
    }
}
