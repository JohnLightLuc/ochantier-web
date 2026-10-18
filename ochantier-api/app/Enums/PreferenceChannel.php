<?php

namespace App\Enums;

/**
 * Valeurs de `channel` pour la table `notification_preferences`.
 */
enum PreferenceChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Notification push',
            self::Sms => 'SMS',
            self::Email => 'E-mail',
        };
    }
}
