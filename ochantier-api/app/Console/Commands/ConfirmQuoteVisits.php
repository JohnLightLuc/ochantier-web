<?php

namespace App\Console\Commands;

use App\Support\Promoter\QuoteRequests;
use Illuminate\Console\Command;

/**
 * Confirme d’office les visites de devis déclarées faites que le promoteur n’a ni confirmées ni contestées dans le
 * délai (réglage quotes.visit_confirmation_days) : les frais de visite deviennent dus au professionnel.
 */
class ConfirmQuoteVisits extends Command
{
    protected $signature = 'ochantier:confirm-quote-visits';

    protected $description = 'Confirme d’office les visites de devis restées sans réponse du promoteur';

    public function handle(QuoteRequests $quotes): int
    {
        $count = $quotes->confirmOverdueVisits();
        $this->info($count.' visite'.($count > 1 ? 's confirmées' : ' confirmée').' d’office.');

        return self::SUCCESS;
    }
}
