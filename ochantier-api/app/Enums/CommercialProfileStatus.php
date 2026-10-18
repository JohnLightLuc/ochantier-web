<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un profil commercial.
 */
enum CommercialProfileStatus: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Termine = 'termine';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Termine => 'Terminé',
        };
    }
}
