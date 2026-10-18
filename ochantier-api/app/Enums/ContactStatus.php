<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour la table `worker_offer_contacts`.
 */
enum ContactStatus: string
{
    case EnAttente = 'en_attente';
    case Repondu = 'repondu';
    case Expire = 'expire';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Repondu => 'Répondu',
            self::Expire => 'Expiré',
            self::Annule => 'Annulé',
        };
    }
}
