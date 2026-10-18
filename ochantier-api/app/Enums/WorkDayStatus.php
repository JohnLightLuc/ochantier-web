<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une journée travaillée.
 */
enum WorkDayStatus: string
{
    case EnCours = 'en_cours';
    case AValider = 'a_valider';
    case Valide = 'valide';
    case Rejete = 'rejete';
    case Absent = 'absent';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::AValider => 'À valider',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
            self::Absent => 'Absent',
            self::Annule => 'Annulé',
        };
    }
}
