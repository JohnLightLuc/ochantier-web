<?php

namespace App\Console\Commands;

use App\Enums\StaffingRequestStatus;
use App\Models\StaffingRequest;
use App\Support\Contractor\ContractorStaffing;
use Illuminate\Console\Command;

/**
 * Simule localement la recherche des ouvriers d’une recherche d’effectifs d’entrepreneur (toutes celles en
 * recherche, ou celle indiquée) : l’offre est constituée automatiquement. Refusée en production.
 */
class SimulateStaffing extends Command
{
    protected $signature = 'ochantier:simulate-staffing {request? : Référence (EF-2026-0001) ou identifiant}';

    protected $description = 'Constitue l’offre d’ouvriers des recherches d’effectifs en attente (hors production)';

    public function handle(ContractorStaffing $staffing): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : l’offre est constituée par le Back Office.');

            return self::FAILURE;
        }

        $search = $this->argument('request');
        $requests = StaffingRequest::query()->where('status', StaffingRequestStatus::Recherche->value)
            ->when($search, fn ($query) => $query->where(fn ($query) => $query->where('reference', $search)->when(str($search)->isUuid(), fn ($query) => $query->orWhere('id', $search))))
            ->get();

        if ($requests->isEmpty()) {
            $this->error('Aucune recherche d’effectifs en attente.');

            return self::FAILURE;
        }

        foreach ($requests as $request) {
            $result = $staffing->search($request);
            $this->info($result->reference.' : '.$result->status->label().'.');
        }

        return self::SUCCESS;
    }
}
