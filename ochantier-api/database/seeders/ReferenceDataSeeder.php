<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de référence indispensables au fonctionnement de la plateforme, y compris en production.
 * Ré-exécutable : les lignes existantes sont mises à jour ou conservées, jamais dupliquées.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GeographySeeder::class,
            TradeSeeder::class,
            DocumentTypeSeeder::class,
            CommercialOfferSeeder::class,
            AdminAccessSeeder::class,
            PlatformRulesSeeder::class,
            HelpCenterSeeder::class,
        ]);
    }
}
