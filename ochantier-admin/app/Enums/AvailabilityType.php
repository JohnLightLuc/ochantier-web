<?php

namespace App\Enums;

/**
 * Type d’une période déclarée dans le calendrier de l’ouvrier.
 */
enum AvailabilityType: string
{
    case Available = 'available';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
    case Rest = 'rest';
    case DeclaredAbsence = 'declared_absence';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Partial => 'Partiellement disponible',
            self::Unavailable => 'Indisponible',
            self::Rest => 'Repos',
            self::DeclaredAbsence => 'Absence déclarée',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'g',
            self::Partial => 'a',
            self::Unavailable, self::DeclaredAbsence => 'r',
            self::Rest => 'n',
        };
    }

    /**
     * Les périodes qui empêchent une affectation.
     */
    public function blocksAssignment(): bool
    {
        return in_array($this, [self::Unavailable, self::Rest, self::DeclaredAbsence], true);
    }
}
