<?php

namespace App\Enums;

/**
 * Valeurs de `role` pour une partie à un litige.
 */
enum DisputePartyRole: string
{
    case Demandeur = 'demandeur';
    case Defendeur = 'defendeur';
    case Tiers = 'tiers';

    public function label(): string
    {
        return match ($this) {
            self::Demandeur => 'Demandeur',
            self::Defendeur => 'Défendeur',
            self::Tiers => 'Tiers',
        };
    }
}
