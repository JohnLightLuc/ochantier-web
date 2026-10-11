<?php

namespace App\Enums;

enum NeedDocumentStatus: string
{
    case Validated = 'validated';
    case ToReview = 'to_review';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validé',
            self::ToReview => 'À vérifier',
            self::Missing => 'Manquant',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Validated => 'g',
            self::ToReview => 'a',
            self::Missing => 'r',
        };
    }
}
