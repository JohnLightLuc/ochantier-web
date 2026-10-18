<?php

namespace App\Enums;

/**
 * Valeurs de `scope` pour un paramètre tarifaire.
 */
enum PricingParameterScope: string
{
    case Global = 'global';
    case OfferType = 'offer_type';
    case SubscriptionPlan = 'subscription_plan';
    case Organization = 'organization';
    case Trade = 'trade';
    case Site = 'site';
    case Need = 'need';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::OfferType => 'Type d’offre',
            self::SubscriptionPlan => 'Formule d’abonnement',
            self::Organization => 'Organisation',
            self::Trade => 'Métier',
            self::Site => 'Chantier',
            self::Need => 'Besoin',
        };
    }
}
