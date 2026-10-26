<?php

namespace App\Console\Commands;

use App\Enums\CommercialObjectiveIndicator;
use App\Models\CommercialProfile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Simule localement l’objectif mensuel d’ouvriers validés fixé par le Back office à un commercial. Refusée en
 * production.
 */
class SimulateCommercialObjective extends Command
{
    protected $signature = 'ochantier:simulate-commercial-objective
        {commercial : Matricule du commercial (COM-00001)}
        {target : Nombre d’ouvriers validés visé}
        {--month= : Mois de l’objectif (AAAA-MM), mois en cours par défaut}';

    protected $description = 'Simule l’objectif mensuel d’ouvriers validés d’un commercial (hors production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les objectifs sont fixés par le Back office.');

            return self::FAILURE;
        }

        $commercial = CommercialProfile::query()->where('employee_number', $this->argument('commercial'))->first();
        $target = (int) $this->argument('target');

        if (! $commercial || $target < 1) {
            $this->error($commercial ? 'L’objectif doit être d’au moins 1 ouvrier validé.' : 'Commercial '.$this->argument('commercial').' introuvable.');

            return self::FAILURE;
        }

        $month = $this->option('month') ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01')->startOfDay() : CarbonImmutable::today()->startOfMonth();
        $commercial->commercialObjectives()->updateOrCreate(
            ['period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(), 'indicator' => CommercialObjectiveIndicator::OuvriersValides],
            ['target_value' => $target],
        );

        $this->info("{$commercial->employee_number} : objectif de {$target} ouvrier(s) validé(s) en {$month->format('m/Y')}.");

        return self::SUCCESS;
    }
}
