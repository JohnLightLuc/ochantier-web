<?php

namespace App\Enums;

/**
 * Valeurs de `environment` pour une intégration.
 */
enum IntegrationEnvironment: string
{
    case BacASable = 'bac_a_sable';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::BacASable => 'Bac à sable',
            self::Production => 'Production',
        };
    }
}
