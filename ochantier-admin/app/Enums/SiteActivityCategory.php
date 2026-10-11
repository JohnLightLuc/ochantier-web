<?php

namespace App\Enums;

/**
 * Catégorie d’un événement du journal d’un chantier.
 */
enum SiteActivityCategory: string
{
    case Creation = 'creation';
    case Validation = 'validation';
    case Status = 'statut';
    case Update = 'modification';
    case Incident = 'incident';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Validation => 'Validation',
            self::Status => 'Statut',
            self::Update => 'Modification',
            self::Incident => 'Incident',
            self::Document => 'Document',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Creation, self::Validation => 'success',
            self::Status => 'info',
            self::Update => 'purple',
            self::Incident => 'danger',
            self::Document => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Creation => 'add_business',
            self::Validation => 'verified',
            self::Status => 'swap_vert',
            self::Update => 'edit',
            self::Incident => 'report',
            self::Document => 'description',
        };
    }
}
