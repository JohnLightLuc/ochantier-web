<?php

namespace App\Enums;

/**
 * Statut de vérification du profil d’un ouvrier.
 */
enum WorkerStatus: string
{
    case Incomplete = 'incomplete';
    case ToReview = 'to_review';
    case InReview = 'in_review';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Profil incomplet',
            self::ToReview => 'À vérifier',
            self::InReview => 'En vérification',
            self::Validated => 'Validé',
            self::Rejected => 'Rejeté',
            self::Suspended => 'Suspendu',
            self::Archived => 'Archivé',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Incomplete, self::Archived => 'n',
            self::ToReview => 'a',
            self::InReview => 'b',
            self::Validated => 'g',
            self::Rejected, self::Suspended => 'r',
        };
    }

    public function isUnderReview(): bool
    {
        return in_array($this, [self::ToReview, self::InReview], true);
    }
}
