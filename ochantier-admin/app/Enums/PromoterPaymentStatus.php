<?php

namespace App\Enums;

/**
 * Situation des paiements d’un promoteur vis-à-vis de la plateforme.
 */
enum PromoterPaymentStatus: string
{
    case UpToDate = 'up_to_date';
    case Pending = 'pending';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::UpToDate => 'À jour',
            self::Pending => 'En attente',
            self::Late => 'Retard',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::UpToDate => 'g',
            self::Pending => 'a',
            self::Late => 'r',
        };
    }
}
