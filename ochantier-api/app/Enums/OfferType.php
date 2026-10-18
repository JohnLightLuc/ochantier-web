<?php

namespace App\Enums;

/**
 * Valeurs de `offer_type` pour une offre.
 */
enum OfferType: string
{
    case Ponctuelle = 'ponctuelle';
    case Temporaire = 'temporaire';
    case Contractuelle = 'contractuelle';

    public function label(): string
    {
        return match ($this) {
            self::Ponctuelle => 'Ponctuelle',
            self::Temporaire => 'Temporaire',
            self::Contractuelle => 'Contractuelle',
        };
    }
}
