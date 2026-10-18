<?php

namespace App\Enums;

/**
 * Canal d’envoi d’une notification.
 */
enum NotificationChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case Email = 'email';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Notification push',
            self::Sms => 'SMS',
            self::Email => 'E-mail',
            self::InApp => 'Dans l’application',
        };
    }
}
