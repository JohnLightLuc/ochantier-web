<?php

namespace App\Enums;

/**
 * Valeurs de `payment_frequency` pour un besoin.
 */
enum PaymentFrequency: string
{
    case Journalier = 'journalier';
    case Hebdomadaire = 'hebdomadaire';
    case Mensuel = 'mensuel';
    case Jalon = 'jalon';

    public function label(): string
    {
        return match ($this) {
            self::Journalier => 'Journalier',
            self::Hebdomadaire => 'Hebdomadaire',
            self::Mensuel => 'Mensuel',
            self::Jalon => 'Jalon',
        };
    }
}
