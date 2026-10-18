<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une déclaration de progression.
 */
enum ProgressDeclarationStatus: string
{
    case Declaree = 'declaree';
    case Verifiee = 'verifiee';
    case Rejetee = 'rejetee';

    public function label(): string
    {
        return match ($this) {
            self::Declaree => 'Déclarée',
            self::Verifiee => 'Vérifiée',
            self::Rejetee => 'Rejetée',
        };
    }
}
