<?php

namespace App\Enums;

/**
 * Cycle de vie du compte d’un commercial : création, invitation, activation, puis exploitation.
 */
enum SalesAgentStatus: string
{
    case Draft = 'draft';
    case Invited = 'invited';
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Invited => 'Invitation envoyée',
            self::PendingActivation => 'Activation en attente',
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Deactivated => 'Désactivé',
            self::Archived => 'Archivé',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Deactivated, self::Archived => 'n',
            self::Invited => 'b',
            self::PendingActivation => 'a',
            self::Active => 'g',
            self::Suspended => 'r',
        };
    }

    /**
     * Compte pas encore activé (invitation en cours).
     */
    public function isPreActivation(): bool
    {
        return in_array($this, [self::Draft, self::Invited, self::PendingActivation], true);
    }

    /**
     * Compte ayant été activé : il a un historique d’activité et de performances.
     */
    public function wasActivated(): bool
    {
        return in_array($this, [self::Active, self::Suspended, self::Deactivated, self::Archived], true);
    }

    /**
     * Éligible au titre de Commercial du mois.
     */
    public function isEligibleForRanking(): bool
    {
        return ! in_array($this, [self::Suspended, self::Deactivated, self::Archived], true);
    }
}
