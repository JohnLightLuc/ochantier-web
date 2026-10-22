<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Support\Matching\MatchingSimulator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Simule localement le contrôle Back office et le matching des offres publiées depuis l’application Promoteur, pour
 * recevoir une proposition d’équipe sans Back office. Refusée en production.
 */
class SimulateMatching extends Command
{
    protected $signature = 'ochantier:simulate-matching
        {--site= : Référence (CH-2026-0001) ou identifiant d’un chantier}
        {--missing=0 : Nombre d’ouvriers à laisser manquer pour obtenir une proposition partielle}';

    protected $description = 'Simule le matching des offres publiées et présente une proposition d’équipe au promoteur (hors production)';

    public function handle(MatchingSimulator $simulator): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : le matching est réalisé par le Back office.');

            return self::FAILURE;
        }

        $site = $this->option('site');
        $offers = $simulator->pendingOffers()
            ->with('site')
            ->when($site, fn ($query) => $query->whereHas('site', fn ($query) => $query->where('reference', $site)->orWhere('id', $site)))
            ->oldest('submitted_at')
            ->get();

        if ($offers->isEmpty()) {
            $this->info('Aucune offre en attente de proposition.');

            return self::SUCCESS;
        }

        foreach ($offers as $offer) {
            /** @var Offer $offer */
            try {
                $proposal = $simulator->simulate($offer, (int) $this->option('missing'));
            } catch (RuntimeException $exception) {
                $this->warn("{$offer->reference} · {$offer->site->label} : {$exception->getMessage()}");

                continue;
            }

            $proposal
                ? $this->info("{$offer->reference} · {$offer->site->label} : proposition {$proposal->reference}, {$proposal->proposed_count}/{$proposal->requested_count} ouvriers, acompte {$proposal->deposit_amount} FCFA.")
                : $this->warn("{$offer->reference} · {$offer->site->label} : aucun ouvrier disponible, la recherche continue.");
        }

        return self::SUCCESS;
    }
}
