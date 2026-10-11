<?php

namespace Database\Seeders;

use App\Models\AnalyticsReport;
use App\Models\AnalyticsSchedule;
use Illuminate\Database\Seeder;

/**
 * Bibliothèque de la maquette : quatre rapports générés et trois programmations (dont une suspendue).
 */
class AnalyticsSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            ['missions', 'Répartition des missions · Août 2026', 'Excel', '0,9 Mo', '2026-09-01', 'Système'],
            ['ouvriers', 'Activité des ouvriers · Sept. 2026', 'PDF', '2,1 Mo', '2026-09-15', 'M. Diabaté'],
            ['chantiers', 'Performance chantiers · T3', 'Excel', '1,8 Mo', '2026-09-18', 'A. Koné'],
            ['financier', 'Rapport financier · Sept. 2026', 'PDF', '2,4 Mo', '2026-09-22', 'N. Yao'],
        ] as [$kind, $name, $format, $size, $date, $author]) {
            AnalyticsReport::create(['kind' => $kind, 'name' => $name, 'format' => $format, 'rows' => 0, 'size' => $size, 'generated_on' => $date, 'author' => $author, 'filters' => 'Aucun filtre', 'rhythm' => 'Mensuel']);
        }
        foreach ([
            ['financier', 'Mensuel', '2026-10-01', 'Direction générale, Direction financière', 'PDF', 'Actif'],
            ['chantiers', 'Hebdomadaire', '2026-10-05', 'Responsables chantiers', 'Excel', 'Actif'],
            ['ouvriers', 'Trimestriel', '2026-10-01', 'Direction des opérations', 'PDF', 'Suspendu'],
        ] as [$kind, $frequency, $next, $recipients, $format, $status]) {
            AnalyticsSchedule::create(['kind' => $kind, 'frequency' => $frequency, 'next_run' => $next, 'recipients' => $recipients, 'format' => $format, 'status' => $status]);
        }
    }
}
