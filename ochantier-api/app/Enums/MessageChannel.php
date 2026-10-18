<?php

namespace App\Enums;

/**
 * Canal d’envoi d’un code ou d’une invitation.
 */
enum MessageChannel: string
{
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'E-mail',
        };
    }
}
