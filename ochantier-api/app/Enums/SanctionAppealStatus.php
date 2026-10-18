<?php

namespace App\Enums;

/**
 * Valeurs de `appeal_status` pour une sanction d’ouvrier.
 */
enum SanctionAppealStatus: string
{
    case Aucun = 'aucun';
    case Demande = 'demande';
    case Accepte = 'accepte';
    case Rejete = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::Aucun => 'Aucun',
            self::Demande => 'Demandé',
            self::Accepte => 'Accepté',
            self::Rejete => 'Rejeté',
        };
    }
}
