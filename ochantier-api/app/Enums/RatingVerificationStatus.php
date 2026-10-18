<?php

namespace App\Enums;

/**
 * Valeurs de `verification_status` pour une évaluation.
 */
enum RatingVerificationStatus: string
{
    case NonVerifie = 'non_verifie';
    case Verifie = 'verifie';
    case Suspect = 'suspect';

    public function label(): string
    {
        return match ($this) {
            self::NonVerifie => 'Non vérifié',
            self::Verifie => 'Vérifié',
            self::Suspect => 'Suspect',
        };
    }
}
