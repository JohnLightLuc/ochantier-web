<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Support\Promoter\SiteClosure;
use Illuminate\Console\Command;

/**
 * Simule localement la clôture d’un chantier par le Back office : missions et affectations terminées, chantier
 * « Terminé », pour tester l’évaluation de l’équipe et le rapport de fin de chantier. Refusée en production.
 */
class SimulateSiteEnd extends Command
{
    private const REASON = 'Simulation locale de la clôture Back office';

    protected $signature = 'ochantier:simulate-site-end
        {site : Référence (CH-2026-0001) ou identifiant du chantier à clôturer}';

    protected $description = 'Simule la clôture d’un chantier confirmé ou en cours par le Back office (hors production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les chantiers sont clôturés par le Back office.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('site');
        $site = Site::query()
            ->where('reference', $reference)
            ->when(str($reference)->isUuid(), fn ($query) => $query->orWhere('id', $reference))
            ->first();

        if (! $site) {
            $this->error("Chantier {$reference} introuvable.");

            return self::FAILURE;
        }

        if (! in_array($site->status, [SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu], true)) {
            $this->error("{$site->label} : seul un chantier confirmé, en cours ou suspendu peut être clôturé ({$site->status->label()}).");

            return self::FAILURE;
        }

        $closed = app(SiteClosure::class)->close($site, self::REASON);

        $this->info("{$site->label} : chantier terminé, {$closed} mission(s) clôturée(s).");

        return self::SUCCESS;
    }
}
