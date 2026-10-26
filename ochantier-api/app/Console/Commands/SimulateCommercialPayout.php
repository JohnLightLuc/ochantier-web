<?php

namespace App\Console\Commands;

use App\Enums\CommercialProfileStatus;
use App\Models\CommercialProfile;
use App\Support\Commercial\CommercialEarnings;
use App\Support\Commercial\CommercialNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Simule localement le versement mensuel des commissions confirmées aux commerciaux par le Back office. Refusée en
 * production.
 */
class SimulateCommercialPayout extends Command
{
    protected $signature = 'ochantier:simulate-commercial-payout
        {--month= : Mois des commissions à verser (AAAA-MM), mois en cours par défaut}
        {--commercial= : Matricule (COM-00001) d’un seul commercial}';

    protected $description = 'Simule le versement des commissions confirmées du mois aux commerciaux (hors production)';

    public function handle(CommercialEarnings $earnings, CommercialNotifier $notifier): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les commissions sont versées par le Back office.');

            return self::FAILURE;
        }

        $month = $this->option('month') ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01')->startOfDay() : CarbonImmutable::today()->startOfMonth();
        $paid = 0;

        CommercialProfile::query()
            ->where('status', CommercialProfileStatus::Actif)
            ->when($this->option('commercial'), fn ($query, $number) => $query->where('employee_number', $number))
            ->with('user')
            ->each(function (CommercialProfile $commercial) use ($earnings, $notifier, $month, &$paid): void {
                if ($payout = $earnings->pay($commercial, $month)) {
                    $notifier->payoutPaid($payout->load('commercial.user'));
                    $this->info("{$commercial->employee_number} : {$earnings->money($payout->total_amount)} versés ({$payout->reference}).");
                    $paid++;
                }
            });

        if ($paid === 0) {
            $this->info('Aucune commission confirmée à verser.');
        }

        return self::SUCCESS;
    }
}
