<?php

namespace App\Enums;

/**
 * Valeurs de `audience` pour un type de pièce.
 */
enum DocumentAudience: string
{
    case Worker = 'worker';
    case Promoter = 'promoter';
    case Organization = 'organization';
    case Commercial = 'commercial';
    case Site = 'site';
    case Offer = 'offer';
    case Need = 'need';
    case Mission = 'mission';
    case Subscription = 'subscription';
    case Dispute = 'dispute';
    case Claim = 'claim';
    case Incident = 'incident';
    case Ticket = 'ticket';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Ouvrier',
            self::Promoter => 'Promoteur',
            self::Organization => 'Organisation',
            self::Commercial => 'Commercial',
            self::Site => 'Chantier',
            self::Offer => 'Offre',
            self::Need => 'Besoin',
            self::Mission => 'Mission',
            self::Subscription => 'Abonnement',
            self::Dispute => 'Litige',
            self::Claim => 'Réclamation',
            self::Incident => 'Incident',
            self::Ticket => 'Ticket',
            self::Other => 'Autre',
        };
    }
}
