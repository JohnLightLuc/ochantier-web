<?php

namespace App\Enums;

/**
 * Valeurs de `service_type` pour une intégration.
 */
enum IntegrationServiceType: string
{
    case Paiement = 'paiement';
    case Sms = 'sms';
    case Push = 'push';
    case Email = 'email';
    case Cartographie = 'cartographie';
    case Stockage = 'stockage';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Paiement => 'Paiement',
            self::Sms => 'SMS',
            self::Push => 'Notification push',
            self::Email => 'E-mail',
            self::Cartographie => 'Cartographie',
            self::Stockage => 'Stockage',
            self::Autre => 'Autre',
        };
    }
}
