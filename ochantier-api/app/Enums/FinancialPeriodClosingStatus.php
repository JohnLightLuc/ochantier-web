<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une clôture mensuelle.
 */
enum FinancialPeriodClosingStatus: string
{
    case Ouverte = 'ouverte';
    case EnCloture = 'en_cloture';
    case Cloturee = 'cloturee';
    case Reouverte = 'reouverte';

    public function label(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::EnCloture => 'En clôture',
            self::Cloturee => 'Clôturée',
            self::Reouverte => 'Réouverte',
        };
    }
}
