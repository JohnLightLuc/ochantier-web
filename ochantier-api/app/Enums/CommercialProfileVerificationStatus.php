<?php

namespace App\Enums;

/**
 * Valeurs de `verification_status` pour un profil commercial.
 */
enum CommercialProfileVerificationStatus: string
{
    case Incomplet = 'incomplet';
    case Soumis = 'soumis';
    case ACorriger = 'a_corriger';
    case Valide = 'valide';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Incomplet => 'Incomplet',
            self::Soumis => 'Soumis',
            self::ACorriger => 'À corriger',
            self::Valide => 'Validé',
            self::Suspendu => 'Suspendu',
        };
    }
}
