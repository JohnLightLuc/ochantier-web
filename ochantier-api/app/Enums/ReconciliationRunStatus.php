<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un rapprochement.
 */
enum ReconciliationRunStatus: string
{
    case EnCours = 'en_cours';
    case Termine = 'termine';
    case Echoue = 'echoue';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Termine => 'Terminé',
            self::Echoue => 'Échoué',
        };
    }
}
