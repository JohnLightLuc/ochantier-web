<?php

namespace App\Enums;

/**
 * Valeurs de `channel` pour une relance de prospect.
 */
enum ProspectFollowupChannel: string
{
    case Appel = 'appel';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Visite = 'visite';

    public function label(): string
    {
        return match ($this) {
            self::Appel => 'Appel',
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
            self::Visite => 'Visite',
        };
    }
}
