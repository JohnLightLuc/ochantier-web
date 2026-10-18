<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un abonnement.
 */
enum SubscriptionStatus: string
{
    case Essai = 'essai';
    case Active = 'active';
    case EnRetard = 'en_retard';
    case Suspendue = 'suspendue';
    case Resiliee = 'resiliee';
    case Expiree = 'expiree';

    public function label(): string
    {
        return match ($this) {
            self::Essai => 'Essai',
            self::Active => 'Active',
            self::EnRetard => 'En retard',
            self::Suspendue => 'Suspendue',
            self::Resiliee => 'Résiliée',
            self::Expiree => 'Expirée',
        };
    }
}
