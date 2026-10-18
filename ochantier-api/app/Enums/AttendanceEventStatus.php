<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un pointage.
 */
enum AttendanceEventStatus: string
{
    case Soumis = 'soumis';
    case Valide = 'valide';
    case Rejete = 'rejete';
    case Corrige = 'corrige';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Soumis => 'Soumis',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
            self::Corrige => 'Corrigé',
            self::Annule => 'Annulé',
        };
    }
}
