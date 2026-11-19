<?php

namespace App\Console\Commands;

use App\Support\Contractor\ContractorSubscriptions;
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

    public function handle(PromoterSubscriptions $subscriptions, ContractorSubscriptions $contractors): int
    {
        $counts = $subscriptions->renew();

        $this->info("{$counts['invoiced']} facture(s) émise(s), {$counts['late']} abonnement(s) en retard, {$counts['expired']} expiré(s), {$counts['ended']} résilié(s).");

        $pro = $contractors->renew();
        $this->info("Entrepreneurs : {$pro['renewal_invoiced']} facture(s) de renouvellement, {$pro['expired']} expiré(s), {$pro['ended']} résilié(s).");

        return self::SUCCESS;
    }
}
