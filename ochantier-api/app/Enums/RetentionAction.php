<?php

namespace App\Enums;

/**
 * Valeurs de `action` pour la table `data_retention_policies`.
 */
enum RetentionAction: string
{
    case Supprimer = 'supprimer';
    case Anonymiser = 'anonymiser';
    case Archiver = 'archiver';

    public function label(): string
    {
        return match ($this) {
            self::Supprimer => 'Supprimer',
            self::Anonymiser => 'Anonymiser',
            self::Archiver => 'Archiver',
        };
    }
}
