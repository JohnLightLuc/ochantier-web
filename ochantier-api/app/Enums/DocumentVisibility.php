<?php

namespace App\Enums;

/**
 * Valeurs de `visibility` pour un document.
 */
enum DocumentVisibility: string
{
    case Interne = 'interne';
    case PromoteurMission = 'promoteur_mission';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Interne => 'Interne',
            self::PromoteurMission => 'Promoteur de la mission',
            self::Public => 'Public',
        };
    }
}
