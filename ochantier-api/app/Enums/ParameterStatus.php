<?php

namespace App\Enums;

/**
 * Statut d’une valeur de paramètre soumise à approbation.
 */
enum ParameterStatus: string
{
    case EnAttenteApprobation = 'en_attente_approbation';
    case Actif = 'actif';
    case Expire = 'expire';
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::EnAttenteApprobation => 'En attente d’approbation',
            self::Actif => 'Actif',
            self::Expire => 'Expiré',
            self::Refuse => 'Refusé',
        };
    }
}
