<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une campagne de diffusion.
 */
enum BroadcastCampaignStatus: string
{
    case Brouillon = 'brouillon';
    case AValider = 'a_valider';
    case Programmee = 'programmee';
    case Envoyee = 'envoyee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::AValider => 'À valider',
            self::Programmee => 'Programmée',
            self::Envoyee => 'Envoyée',
            self::Annulee => 'Annulée',
        };
    }
}
