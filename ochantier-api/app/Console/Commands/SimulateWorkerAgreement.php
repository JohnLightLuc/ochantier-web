<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Support\Contractor\ContractorTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Simule localement l’accord ou le refus d’un ouvrier sur une affectation directe d’un entrepreneur. Sans
 * identifiant, traite toutes les affectations en attente. Refusée en production.
 */
class SimulateWorkerAgreement extends Command
{
    protected $signature = 'ochantier:simulate-worker-agreement
        {decision : accepter ou refuser}
        {assignment? : Identifiant de l’affectation (toutes les affectations en attente sinon)}';

    protected $description = 'Simule l’accord ou le refus d’un ouvrier sur une affectation directe d’entrepreneur (hors production)';

    public function handle(ContractorTeam $team): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : l’accord de l’ouvrier est recueilli par le Back Office.');

            return self::FAILURE;
        }

        $decision = (string) $this->argument('decision');

        if (! in_array($decision, ['accepter', 'refuser'], true)) {
            $this->error('Décision inconnue : accepter ou refuser.');

            return self::FAILURE;
        }

        $id = $this->argument('assignment');
        $assignments = Assignment::query()->where('status', 'en_attente')
            ->when($id, fn ($query) => Str::isUuid($id) ? $query->whereKey($id) : $query->whereRaw('false'))
            ->get();

        if ($assignments->isEmpty()) {
            $this->error('Aucune affectation en attente d’accord.');

            return self::FAILURE;
        }

        try {
            $assignments->each(fn (Assignment $assignment) => $team->decideAgreement($assignment, $decision === 'accepter'));
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($assignments->count().' affectation(s) '.($decision === 'accepter' ? 'acceptée(s)' : 'refusée(s)').'.');

        return self::SUCCESS;
    }
}
