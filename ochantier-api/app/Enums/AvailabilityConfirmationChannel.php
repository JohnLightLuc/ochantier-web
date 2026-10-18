<?php

namespace App\Enums;

/**
 * Valeurs de `confirmation_channel` pour une disponibilité d’ouvrier.
 */
enum AvailabilityConfirmationChannel: string
{
    case Appel = 'appel';
    case Sms = 'sms';
    case Visite = 'visite';
    case Application = 'application';

    public function label(): string
    {
        return match ($this) {
            self::Appel => 'Appel',
            self::Sms => 'SMS',
            self::Visite => 'Visite',
            self::Application => 'Application',
        };
    }
}
