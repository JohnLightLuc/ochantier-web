<?php

namespace App\Enums;

/**
 * Valeurs de `response_mode` : réponse d’un entrepreneur à une offre contractuelle.
 */
enum ContractorResponseMode: string
{
    case Conforme = 'conforme';
    case ContreProposition = 'contre_proposition';

    public function label(): string
    {
        return match ($this) {
            self::Conforme => 'Offre conforme',
            self::ContreProposition => 'Contre-proposition',
        };
    }
}
