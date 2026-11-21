<?php

namespace App\Enums;

/**
 * Statut d’une candidature « Devenir ouvrier » reçue par le site public.
 */
enum LandingApplicationStatus: string
{
    case Nouvelle = 'nouvelle';
    case EnVerification = 'en_verification';
    case Convertie = 'convertie';
    case Refusee = 'refusee';

    public function label(): string
    {
        return match ($this) {
            self::Nouvelle => 'Nouvelle',
            self::EnVerification => 'En vérification',
            self::Convertie => 'Convertie',
            self::Refusee => 'Refusée',
        };
    }
}
