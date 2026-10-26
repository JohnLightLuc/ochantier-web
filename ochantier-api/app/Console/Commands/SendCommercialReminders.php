<?php

namespace App\Console\Commands;

use App\Support\Commercial\CommercialNotifier;
use Illuminate\Console\Command;

/**
 * Rappels quotidiens aux commerciaux (planifiés chaque matin) : relances conseillées et, le lundi, objectif du mois et
 * classement.
 */
class SendCommercialReminders extends Command
{
    protected $signature = 'ochantier:commercial-reminders';

    protected $description = 'Envoie aux commerciaux les relances conseillées et, le lundi, leur objectif et leur classement';

    public function handle(CommercialNotifier $notifier): int
    {
        $counts = $notifier->reminders();

        $this->info("{$counts['follow_ups']} relance(s) conseillée(s), {$counts['objectives']} rappel(s) d’objectif, {$counts['rankings']} classement(s).");

        return self::SUCCESS;
    }
}
