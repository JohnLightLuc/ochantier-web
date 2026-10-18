<?php

namespace App\Enums;

/**
 * Valeurs de `profile_kind` pour un profil promoteur.
 */
enum PromoterProfileKind: string
{
    case Particulier = 'particulier';
    case Technicien = 'technicien';
    case Entreprise = 'entreprise';

    public function label(): string
    {
        return match ($this) {
            self::Particulier => 'Particulier',
            self::Technicien => 'Technicien',
            self::Entreprise => 'Entreprise',
        };
    }
}
