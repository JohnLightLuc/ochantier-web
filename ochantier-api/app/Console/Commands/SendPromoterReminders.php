<?php

namespace App\Console\Commands;

use App\Support\Promoter\PromoterNotifier;
use Illuminate\Console\Command;

/**
 * Rappels quotidiens aux promoteurs (planifiés chaque matin) : paiements à régler demain, paiements en retard et
 * journées de pointage de la veille à valider.
 */
class SendPromoterReminders extends Command
{
    protected $signature = 'ochantier:promoter-reminders';

    protected $description = 'Envoie aux promoteurs les rappels de paiements et de pointages à valider';

    public function handle(PromoterNotifier $notifier): int
    {
        $counts = $notifier->reminders();

        $this->info("{$counts['due']} rappel(s) d’échéance, {$counts['late']} retard(s) de paiement, {$counts['attendance']} journée(s) à valider.");

        return self::SUCCESS;
    }
}
