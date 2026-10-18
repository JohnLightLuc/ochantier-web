<?php

namespace App\Enums;

/**
 * Valeurs de `indicator` pour un objectif commercial.
 */
enum CommercialObjectiveIndicator: string
{
    case Prospects = 'prospects';
    case DossiersComplets = 'dossiers_complets';
    case OuvriersValides = 'ouvriers_valides';
    case OuvriersAffectes = 'ouvriers_affectes';

    public function label(): string
    {
        return match ($this) {
            self::Prospects => 'Prospects',
            self::DossiersComplets => 'Dossiers complets',
            self::OuvriersValides => 'Ouvriers validés',
            self::OuvriersAffectes => 'Ouvriers affectés',
        };
    }
}
