<?php

namespace App\Enums;

/**
 * Valeurs de `schedule_type` pour une échéance.
 */
enum PaymentScheduleType: string
{
    case Acompte = 'acompte';
    case Journaliere = 'journaliere';
    case Hebdomadaire = 'hebdomadaire';
    case Mensuelle = 'mensuelle';
    case Jalon = 'jalon';
    case SeuilR = 'seuil_r';
    case Solde = 'solde';
    case Regularisation = 'regularisation';

    public function label(): string
    {
        return match ($this) {
            self::Acompte => 'Acompte',
            self::Journaliere => 'Journalière',
            self::Hebdomadaire => 'Hebdomadaire',
            self::Mensuelle => 'Mensuelle',
            self::Jalon => 'Jalon',
            self::SeuilR => 'Seuil R',
            self::Solde => 'Solde',
            self::Regularisation => 'Régularisation',
        };
    }
}
