<?php

namespace App\Enums;

/**
 * Valeurs de `moderation_status` pour une évaluation.
 */
enum RatingModerationStatus: string
{
    case Signale = 'signale';
    case Maintenu = 'maintenu';
    case Masque = 'masque';

    public function label(): string
    {
        return match ($this) {
            self::Signale => 'Signalé',
            self::Maintenu => 'Maintenu',
            self::Masque => 'Masqué',
        };
    }
}
