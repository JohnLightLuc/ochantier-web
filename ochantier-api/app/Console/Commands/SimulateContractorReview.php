<?php

namespace App\Console\Commands;

use App\Models\Contractor;
use App\Support\Admin\ContractorVerification;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Simule localement la décision du Back Office sur le dossier d’un entrepreneur : validation, corrections, refus,
 * suspension ou réactivation. Refusée en production.
 */
class SimulateContractorReview extends Command
{
    protected $signature = 'ochantier:simulate-contractor-review
        {contractor : Référence (ENT-00001), identifiant, téléphone ou e-mail du compte, ou téléphone du dossier}
        {decision : valider, corriger, refuser, suspendre ou reactiver}
        {--motif= : Motif des corrections, du refus ou de la suspension}';

    protected $description = 'Simule la décision du Back Office sur le dossier KYB/KYC d’un entrepreneur (hors production)';

    public function handle(ContractorVerification $verification): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les dossiers sont contrôlés par le Back Office.');

            return self::FAILURE;
        }

        $search = (string) $this->argument('contractor');
        $digits = preg_replace('/\D/', '', $search);
        $contractor = Contractor::query()
            ->where('reference', $search)
            ->when(str($search)->isUuid(), fn ($query) => $query->orWhere('id', $search))
            ->when(strlen($digits) >= 8, fn ($query) => $query->orWhere('phone_number', 'like', '%'.substr($digits, -10)))
            ->orWhereHas('user', fn ($query) => $query->where('email', $search)->when(strlen($digits) >= 8, fn ($query) => $query->orWhere('phone', 'like', '%'.$digits)))
            ->first();

        if (! $contractor) {
            $this->error("Entrepreneur {$search} introuvable.");

            return self::FAILURE;
        }

        $reason = $this->option('motif');

        try {
            match ($this->argument('decision')) {
                'valider' => $verification->approve($contractor),
                'corriger' => $verification->requestCorrections($contractor, $reason ?: 'Pièce d’identité illisible (simulation).'),
                'refuser' => $verification->reject($contractor, $reason ?: 'Dossier non conforme (simulation).'),
                'suspendre' => $verification->suspend($contractor, $reason ?: 'Suspension de démonstration.'),
                'reactiver' => $verification->reactivate($contractor),
                default => throw new RuntimeException('Décision inconnue : valider, corriger, refuser, suspendre ou reactiver.'),
            };
        } catch (RuntimeException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $contractor->refresh();
        $this->info("{$contractor->name} ({$contractor->reference}) : dossier {$contractor->verification_status->label()}, compte {$contractor->status->label()}.");

        return self::SUCCESS;
    }
}
