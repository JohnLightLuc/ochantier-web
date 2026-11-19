<?php

namespace App\Enums;

/**
 * Rôle d’un collaborateur dans le compte d’un entrepreneur.
 */
enum ContractorMemberRole: string
{
    case ChefChantier = 'chef_chantier';
    case Technicien = 'technicien';
    case Comptable = 'comptable';
    case Observateur = 'observateur';

    public function label(): string
    {
        return match ($this) {
            self::ChefChantier => 'Chef de chantier',
            self::Technicien => 'Technicien',
            self::Comptable => 'Comptable',
            self::Observateur => 'Observateur',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChefChantier => 'Gère les équipes, le pointage et les validations de journée.',
            self::Technicien => 'Saisit le pointage et les photos sur les chantiers.',
            self::Comptable => 'Consulte les paiements, factures et rapports.',
            self::Observateur => 'Consultation seule, sans modification.',
        };
    }
}
