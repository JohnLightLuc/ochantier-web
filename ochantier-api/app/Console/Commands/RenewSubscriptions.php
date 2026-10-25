<?php

namespace App\Console\Commands;

use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Console\Command;

/**
 * Fin de période des abonnements (planifiée chaque jour) : facture de renouvellement, passage en retard, expiration
 * après le délai de grâce, fin des abonnements résiliés.
 */
class RenewSubscriptions extends Command
{
    protected $signature = 'ochantier:renew-subscriptions';

    protected $description = 'Renouvelle, met en retard, expire ou termine les abonnements arrivés en fin de période';

    public function handle(PromoterSubscriptions $subscriptions): int
    {
        $counts = $subscriptions->renew();

        $this->info("{$counts['invoiced']} facture(s) émise(s), {$counts['late']} abonnement(s) en retard, {$counts['expired']} expiré(s), {$counts['ended']} résilié(s).");

        return self::SUCCESS;
    }
}
