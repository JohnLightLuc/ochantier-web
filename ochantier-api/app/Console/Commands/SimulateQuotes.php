<?php

namespace App\Console\Commands;

use App\Enums\QuoteRequestStatus;
use App\Models\QuoteRequest;
use App\Support\Promoter\QuoteSimulator;
use Illuminate\Console\Command;

/**
 * Simule localement les techniciens d’une demande de devis : offres reçues tant que la demande est en recherche,
 * puis devis réalisés une fois la commande payée. Refusée en production.
 */
class SimulateQuotes extends Command
{
    protected $signature = 'ochantier:simulate-quotes
        {--site= : Référence (CH-2026-0001) ou identifiant d’un chantier}';

    protected $description = 'Simule les offres des techniciens et la réalisation des devis commandés (hors production)';

    public function handle(QuoteSimulator $simulator): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les techniciens répondent depuis l’application Ouvriers.');

            return self::FAILURE;
        }

        $site = $this->option('site');
        $requests = $simulator->pending()
            ->with('site')
            ->when($site, fn ($query) => $query->whereHas('site', fn ($query) => $query->where('reference', $site)->orWhere('id', $site)))
            ->oldest('submitted_at')
            ->get();

        if ($requests->isEmpty()) {
            $this->info('Aucune demande de devis à faire avancer.');

            return self::SUCCESS;
        }

        foreach ($requests as $request) {
            /** @var QuoteRequest $request */
            $request->status === QuoteRequestStatus::Recherche
                ? $this->info("{$request->reference} · {$request->site->label} : {$simulator->bid($request)} offre(s) de technicien reçue(s).")
                : $this->info("{$request->reference} · {$request->site->label} : {$simulator->complete($request)} devis réalisé(s) et ajouté(s) au chantier.");
        }

        return self::SUCCESS;
    }
}
