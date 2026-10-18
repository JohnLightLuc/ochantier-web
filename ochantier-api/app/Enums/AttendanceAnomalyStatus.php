<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une anomalie de pointage.
 */
enum AttendanceAnomalyStatus: string
{
    case Ouverte = 'ouverte';
    case EnTraitement = 'en_traitement';
    case Resolue = 'resolue';
    case Rejetee = 'rejetee';

    public function label(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::EnTraitement => 'En traitement',
            self::Resolue => 'Résolue',
            self::Rejetee => 'Rejetée',
        };
    }
}
