<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Support\Promoter\ContractorSimulator;
use Illuminate\Console\Command;

/**
 * Simule localement les réponses des entrepreneurs aux offres contractuelles publiées. Refusée en production.
 */
class SimulateContractors extends Command
{
    protected $signature = 'ochantier:simulate-contractors
        {--site= : Référence (CH-2026-0001) ou identifiant d’un chantier}';

    protected $description = 'Simule les offres des entrepreneurs sur les offres contractuelles publiées (hors production)';

    public function handle(ContractorSimulator $simulator): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les entrepreneurs répondent depuis l’application Ouvriers.');

            return self::FAILURE;
        }

        $site = $this->option('site');
        $offers = $simulator->pending()
            ->with('site')
            ->when($site, fn ($query) => $query->whereHas('site', fn ($query) => $query->where('reference', $site)->orWhere('id', $site)))
            ->oldest('published_at')
            ->get();

        if ($offers->isEmpty()) {
            $this->info('Aucune offre contractuelle en attente d’entrepreneurs.');

            return self::SUCCESS;
        }

        foreach ($offers as $offer) {
            /** @var Offer $offer */
            $this->info("{$offer->reference} · {$offer->site->label} : {$simulator->bid($offer)} offre(s) d’entrepreneur reçue(s).");
        }

        return self::SUCCESS;
    }
}
