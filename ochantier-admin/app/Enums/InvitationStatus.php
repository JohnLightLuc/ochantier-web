<?php

namespace App\Enums;

/**
 * État du lien d’activation envoyé au commercial.
 */
enum InvitationStatus: string
{
    case NotSent = 'not_sent';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Activated = 'activated';
    case Expired = 'expired';
    case Failed = 'failed';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Non envoyée',
            self::Sent => 'Envoyée',
            self::Viewed => 'Consultée',
            self::Activated => 'Activée',
            self::Expired => 'Expirée',
            self::Failed => 'Échec d’envoi',
            self::Revoked => 'Révoquée',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NotSent => 'n',
            self::Sent => 'b',
            self::Viewed => 'a',
            self::Activated => 'g',
            self::Expired => 'o',
            self::Failed, self::Revoked => 'r',
        };
    }
}
