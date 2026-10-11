<?php

namespace App\Enums;

/**
 * Traitement d’un profil choisi par le promoteur : contact, puis validation ou refus, et remplacement.
 */
enum PromoterOfferProfileStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case RefusedByWorker = 'refused_by_worker';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Contacted => 'Contacté',
            self::Accepted => 'Acceptée',
            self::Refused => 'Refusée',
            self::RefusedByWorker => 'Refusée par l’ouvrier',
            self::Replaced => 'Remplacée',
        };
    }

    /**
     * Libellé affiché dans le tableau des profils.
     */
    public function displayLabel(): string
    {
        return match ($this) {
            self::Pending => 'À contacter',
            self::Accepted => 'Validé',
            default => $this->label(),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'a',
            self::Contacted => 'b',
            self::Accepted => 'g',
            self::Refused => 'r',
            self::RefusedByWorker => 'o',
            self::Replaced => 'n',
        };
    }

    public function isRefused(): bool
    {
        return in_array($this, [self::Refused, self::RefusedByWorker], true);
    }
}
