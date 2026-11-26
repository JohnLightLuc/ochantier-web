<?php

namespace App\Enums;

/**
 * Valeurs de `visit_confirmation` : confirmation par le promoteur d’une visite déclarée faite par le professionnel.
 */
enum QuoteVisitConfirmation: string
{
    case Confirmee = 'confirmee';
    case Automatique = 'automatique';
    case Contestee = 'contestee';

    public function label(): string
    {
        return match ($this) {
            self::Confirmee => 'Confirmée',
            self::Automatique => 'Confirmée d’office',
            self::Contestee => 'Contestée',
        };
    }

    /**
     * Visite confirmée : les frais de visite sont dus au professionnel.
     */
    public function isConfirmed(): bool
    {
        return $this !== self::Contestee;
    }
}
