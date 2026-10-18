<?php

namespace App\Enums;

/**
 * Valeurs de `action` pour la table `admin_permissions`.
 */
enum PermissionAction: string
{
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Validate = 'validate';
    case Approve = 'approve';
    case Export = 'export';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Consulter',
            self::Create => 'Créer',
            self::Update => 'Modifier',
            self::Validate => 'Valider',
            self::Approve => 'Approuver',
            self::Export => 'Exporter',
            self::Delete => 'Supprimer',
        };
    }
}
