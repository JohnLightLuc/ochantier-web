<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une réclamation.
 */
enum ClaimStatus: string
{
    case Ouverte = 'ouverte';
    case EnTraitement = 'en_traitement';
    case Resolue = 'resolue';
    case Escaladee = 'escaladee';
    case Rejetee = 'rejetee';
    case Close = 'close';

    public function label(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::EnTraitement => 'En traitement',
            self::Resolue => 'Résolue',
            self::Escaladee => 'Escaladée',
            self::Rejetee => 'Rejetée',
            self::Close => 'Close',
        };
    }
}
