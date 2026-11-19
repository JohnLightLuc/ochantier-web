<?php

namespace App\Console\Commands;

use App\Support\Promoter\PromoterPayments;
use Illuminate\Console\Command;

/**
 * Paiements des promoteurs dont l’issue n’est pas connue (planifiée chaque minute) : demande leur état au
 * prestataire, applique ceux qu’il a encaissés, clôt ceux qu’il a refusés et expire ceux restés sans réponse.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'ochantier:reconcile-payments {--limit=50 : nombre maximal de paiements vérifiés}';

    protected $description = 'Vérifie auprès du prestataire les paiements restés ouverts et les clôt';

    public function handle(PromoterPayments $payments): int
    {
        $counts = $payments->reconcile(max(1, (int) $this->option('limit')));
        $this->info("{$counts['checked']} paiement(s) vérifié(s) : {$counts['succeeded']} réussi(s), {$counts['failed']} échoué(s), {$counts['expired']} expiré(s), {$counts['pending']} toujours en attente.");

        return self::SUCCESS;
    }
}
