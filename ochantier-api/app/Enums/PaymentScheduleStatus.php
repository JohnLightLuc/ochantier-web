<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une échéance.
 */
enum PaymentScheduleStatus: string
{
    case Prevue = 'prevue';
    case Exigible = 'exigible';
    case PartiellementPayee = 'partiellement_payee';
    case Payee = 'payee';
    case EnRetard = 'en_retard';
    case Contestee = 'contestee';
    case Annulee = 'annulee';
    case Remboursee = 'remboursee';

    public function label(): string
    {
        return match ($this) {
            self::Prevue => 'Prévue',
            self::Exigible => 'Exigible',
            self::PartiellementPayee => 'Partiellement payée',
            self::Payee => 'Payée',
            self::EnRetard => 'En retard',
            self::Contestee => 'Contestée',
            self::Annulee => 'Annulée',
            self::Remboursee => 'Remboursée',
        };
    }
}
