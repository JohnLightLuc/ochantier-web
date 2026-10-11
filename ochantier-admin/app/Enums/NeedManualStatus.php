<?php

namespace App\Enums;

/**
 * Statut posé par l’administration sur un besoin et ses offres ; à défaut, le statut est calculé à partir de la couverture.
 */
enum NeedManualStatus: string
{
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Suspended => 'Suspendu',
            self::Cancelled => 'Annulé',
            self::Closed => 'Clôturé',
            self::Archived => 'Archivé',
        };
    }

    /**
     * Libellé au féminin, pour une offre.
     */
    public function offerLabel(): string
    {
        return match ($this) {
            self::Suspended => 'Suspendue',
            self::Cancelled => 'Annulée',
            self::Closed => 'Clôturée',
            self::Archived => 'Archivée',
        };
    }

    /**
     * Lecture seule : plus aucun document ni aucune modification.
     */
    public function isFinal(): bool
    {
        return $this !== self::Suspended;
    }
}
