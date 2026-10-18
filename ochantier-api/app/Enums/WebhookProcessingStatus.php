<?php

namespace App\Enums;

/**
 * Valeurs de `processing_status` pour la table `payment_webhook_events`.
 */
enum WebhookProcessingStatus: string
{
    case Recu = 'recu';
    case Traite = 'traite';
    case Ignore = 'ignore';
    case Erreur = 'erreur';

    public function label(): string
    {
        return match ($this) {
            self::Recu => 'Reçu',
            self::Traite => 'Traité',
            self::Ignore => 'Ignoré',
            self::Erreur => 'Erreur',
        };
    }
}
