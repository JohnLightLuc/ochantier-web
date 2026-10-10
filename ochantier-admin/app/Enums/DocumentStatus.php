<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Verified = 'verified';
    case ToReview = 'to_review';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Missing = 'missing';

    /**
     * Ordre de gravité pour l’état KYC global : le premier statut présent parmi les pièces obligatoires l’emporte.
     *
     * @var array<int, self>
     */
    public const SEVERITY = [self::Missing, self::Rejected, self::Expired, self::ToReview];

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Vérifié',
            self::ToReview => 'À vérifier',
            self::Expired => 'Expiré',
            self::Rejected => 'Rejeté',
            self::Missing => 'Manquant',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Verified => 'g',
            self::ToReview => 'a',
            self::Expired => 'o',
            self::Rejected => 'r',
            self::Missing => 'n',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Verified => 'verified',
            self::ToReview => 'schedule',
            self::Expired => 'event_busy',
            self::Rejected => 'cancel',
            self::Missing => 'remove_circle',
        };
    }
}
