<?php

namespace App\Console\Commands;

use App\Models\Replacement;
use App\Support\Matching\MatchingSimulator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Simule localement la recherche de remplaçants par le Back office, pour qu’un promoteur reçoive des remplaçants à
 * choisir sans Back office. Refusée en production.
 */
class SimulateReplacement extends Command
{
    protected $signature = 'ochantier:simulate-replacement
        {--site= : Référence (CH-2026-0001) ou identifiant d’un chantier}';

    protected $description = 'Simule la recherche de remplaçants des remplacements demandés par les promoteurs (hors production)';

    public function handle(MatchingSimulator $simulator): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les remplaçants sont proposés par le Back office.');

            return self::FAILURE;
        }

        $site = $this->option('site');
        $replacements = $simulator->pendingReplacements()
            ->with(['mission.site', 'originalAssignment.worker', 'unavailabilityRequest'])
            ->when($site, fn ($query) => $query->whereHas('mission.site', fn ($query) => $query->where('reference', $site)->orWhere('id', $site)))
            ->oldest()
            ->get();

        if ($replacements->isEmpty()) {
            $this->info('Aucun remplacement en attente de remplaçants.');

            return self::SUCCESS;
        }

        foreach ($replacements as $replacement) {
            /** @var Replacement $replacement */
            $label = $replacement->mission->site->label.' · '.trim($replacement->originalAssignment->worker->first_name.' '.$replacement->originalAssignment->worker->last_name);

            try {
                $count = $simulator->simulateReplacement($replacement);
            } catch (RuntimeException $exception) {
                $this->warn("{$label} : {$exception->getMessage()}");

                continue;
            }

            $count > 0
                ? $this->info("{$label} : {$count} remplaçant(s) proposé(s).")
                : $this->warn("{$label} : aucun remplaçant disponible, la recherche continue.");
        }

        return self::SUCCESS;
    }
}
