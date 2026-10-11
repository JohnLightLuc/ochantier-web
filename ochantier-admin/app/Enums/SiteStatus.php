<?php

namespace App\Enums;

/**
 * Cycle de vie d’un chantier, de sa création par le promoteur à son archivage.
 */
enum SiteStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Recruiting = 'recruiting';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Late = 'late';
    case Suspended = 'suspended';
    case Interrupted = 'interrupted';
    case Completed = 'completed';
    case PendingClosure = 'pending_closure';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Planned => 'Planifié',
            self::Recruiting => 'Recrutement en cours',
            self::Ready => 'Prêt à démarrer',
            self::InProgress => 'En cours',
            self::Late => 'En retard',
            self::Suspended => 'Suspendu',
            self::Interrupted => 'Interrompu',
            self::Completed => 'Terminé',
            self::PendingClosure => 'En attente de clôture',
            self::Closed => 'Clôturé',
            self::Cancelled => 'Annulé',
            self::Archived => 'Archivé',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Closed, self::Cancelled, self::Archived => 'n',
            self::Planned, self::Ready => 'b',
            self::Recruiting => 'p',
            self::InProgress, self::Completed => 'g',
            self::Late, self::Suspended, self::Interrupted => 'r',
            self::PendingClosure => 'a',
        };
    }

    /**
     * Travaux en cours d’exécution : pointages et paiements attendus.
     */
    public function isRunning(): bool
    {
        return in_array($this, [self::InProgress, self::Late], true);
    }

    /**
     * Chantier mobilisant une équipe (en exécution, en préparation ou à l’arrêt temporaire).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::InProgress, self::Late, self::Recruiting, self::Ready, self::Suspended, self::Interrupted], true);
    }

    /**
     * Statut définitif : plus aucune action d’exploitation possible.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled, self::Archived], true);
    }

    /**
     * Lecture seule : ni modification ni ajout de document.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::Cancelled, self::Archived], true);
    }

    public function canBePlanned(): bool
    {
        return in_array($this, [self::Draft, self::Planned], true);
    }

    /**
     * Chantier démarré ou sur le point de l’être : incidents et suspension possibles.
     */
    public function isOperational(): bool
    {
        return in_array($this, [self::InProgress, self::Late, self::Recruiting, self::Ready], true);
    }

    public function isStopped(): bool
    {
        return in_array($this, [self::Suspended, self::Interrupted], true);
    }

    public function canBeClosed(): bool
    {
        return in_array($this, [self::Completed, self::PendingClosure], true);
    }

    public function canBeArchived(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }
}
