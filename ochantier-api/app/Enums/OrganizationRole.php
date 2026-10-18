<?php

namespace App\Enums;

/**
 * Valeurs de `role` pour un membre d’organisation.
 */
enum OrganizationRole: string
{
    case Administrateur = 'administrateur';
    case ChefProjet = 'chef_projet';
    case ResponsableChantier = 'responsable_chantier';
    case ResponsableFinancier = 'responsable_financier';
    case Lecteur = 'lecteur';

    public function label(): string
    {
        return match ($this) {
            self::Administrateur => 'Administrateur',
            self::ChefProjet => 'Chef de projet',
            self::ResponsableChantier => 'Responsable de chantier',
            self::ResponsableFinancier => 'Responsable financier',
            self::Lecteur => 'Lecteur',
        };
    }
}
