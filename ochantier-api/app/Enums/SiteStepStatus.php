<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une étape de la chronologie d’un chantier.
 */
enum SiteStepStatus: string
{
    case AFaire = 'a_faire';
    case EnCours = 'en_cours';
    case Validee = 'validee';

    public function label(): string
    {
        return match ($this) {
            self::AFaire => 'À démarrer',
            self::EnCours => 'En cours',
            self::Validee => 'Validée',
        };
    }
}
