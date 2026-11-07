<?php

namespace App\Console\Commands;

use App\Support\Admin\ReportLibrary;
use Illuminate\Console\Command;

/**
 * Rapports programmés arrivés à échéance : génération, bibliothèque et envoi par e-mail.
 */
class RunReportSchedules extends Command
{
    protected $signature = 'ochantier:run-report-schedules';

    protected $description = 'Génère et envoie les rapports programmés arrivés à échéance';

    public function handle(ReportLibrary $library): int
    {
        $counts = $library->runDue();

        $this->info("{$counts['generated']} rapport(s) généré(s), {$counts['sent']} envoyé(s), {$counts['failed']} échec(s).");

        return self::SUCCESS;
    }
}
