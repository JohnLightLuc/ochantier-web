<?php

namespace App\Enums;

/**
 * Valeurs de `channel` pour la table `worker_offer_contacts`.
 */
enum ContactChannel: string
{
    case Appel = 'appel';
    case Sms = 'sms';
    case Notification = 'notification';
    case Whatsapp = 'whatsapp';
    case Visite = 'visite';
    case Application = 'application';

    public function label(): string
    {
        return match ($this) {
            self::Appel => 'Appel',
            self::Sms => 'SMS',
            self::Notification => 'Notification',
            self::Whatsapp => 'WhatsApp',
            self::Visite => 'Visite',
            self::Application => 'Application',
        };
    }
}
