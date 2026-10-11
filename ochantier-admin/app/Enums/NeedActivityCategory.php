<?php

namespace App\Enums;

/**
 * Catégorie d’un événement du journal d’un besoin.
 */
enum NeedActivityCategory: string
{
    case Creation = 'creation';
    case Status = 'statut';
    case Validation = 'validation';
    case Publication = 'publication';
    case Assignment = 'affectation';
    case Dates = 'dates';
    case Document = 'document';
    case Update = 'modification';
    case Notification = 'notification';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Status => 'Statut',
            self::Validation => 'Validation',
            self::Publication => 'Publication',
            self::Assignment => 'Affectation',
            self::Dates => 'Dates',
            self::Document => 'Document',
            self::Update => 'Modification',
            self::Notification => 'Notification',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Creation, self::Validation, self::Assignment => 'success',
            self::Status, self::Notification => 'info',
            self::Publication, self::Update => 'purple',
            self::Dates => 'orange',
            self::Document => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Creation => 'add_circle',
            self::Status => 'swap_vert',
            self::Validation => 'verified',
            self::Publication => 'campaign',
            self::Assignment => 'how_to_reg',
            self::Dates => 'event',
            self::Document => 'description',
            self::Update => 'edit',
            self::Notification => 'notifications',
        };
    }
}
