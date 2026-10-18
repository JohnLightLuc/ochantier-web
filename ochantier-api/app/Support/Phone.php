<?php

namespace App\Support;

/**
 * Numéros de téléphone ivoiriens : 10 chiffres, indicatif +225.
 */
class Phone
{
    /**
     * Les 10 chiffres d’un numéro, sans l’indicatif +225 ni séparateurs.
     */
    public static function digits(?string $phone): string
    {
        return substr(preg_replace('/\D/', '', preg_replace('/^\s*(\+|00)?\s*225\s*/', '', (string) $phone)), 0, 10);
    }

    /**
     * Format enregistré par le Back-office : « +225 07 08 09 10 11 ».
     */
    public static function format(string $digits): string
    {
        return '+225 '.implode(' ', str_split(self::digits($digits), 2));
    }

    /**
     * Numéro masqué pour les messages : « +225 07 •• •• •• 11 ».
     */
    public static function mask(string $digits): string
    {
        $digits = self::digits($digits);

        return '+225 '.substr($digits, 0, 2).' •• •• •• '.substr($digits, -2);
    }
}
