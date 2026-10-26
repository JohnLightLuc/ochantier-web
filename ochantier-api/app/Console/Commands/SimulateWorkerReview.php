<?php

namespace App\Console\Commands;

use App\Models\Worker;
use App\Support\Commercial\WorkerReviews;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Simule localement la décision du Back office sur un dossier ouvrier transmis par un commercial : validation,
 * demande de correction ou refus. Refusée en production.
 */
class SimulateWorkerReview extends Command
{
    protected $signature = 'ochantier:simulate-worker-review
        {worker : Référence (OUV-000123) ou identifiant de l’ouvrier}
        {decision : valider, corriger ou refuser}
        {--motif= : Motif de la correction ou du refus}
        {--piece= : Pour une correction, code du type de pièce à remplacer (identity, worker_photo…)}';

    protected $description = 'Simule la validation, la demande de correction ou le refus d’un dossier ouvrier par le Back office (hors production)';

    public function handle(WorkerReviews $reviews): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : les dossiers sont contrôlés par le Back office.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('worker');
        $worker = Worker::query()->where('reference', $reference)->when(str($reference)->isUuid(), fn ($query) => $query->orWhere('id', $reference))->first();

        if (! $worker) {
            $this->error("Ouvrier {$reference} introuvable.");

            return self::FAILURE;
        }

        $name = trim($worker->first_name.' '.$worker->last_name);

        try {
            match ($this->argument('decision')) {
                'valider' => $reviews->validate($worker),
                'corriger' => $reviews->requestCorrection($worker, $this->option('motif') ?: 'Pièce d’identité illisible à remplacer', $this->option('piece')),
                'refuser' => $reviews->refuse($worker, $this->option('motif') ?: 'Dossier non conforme'),
                default => throw new RuntimeException('Décision inconnue : valider, corriger ou refuser.'),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$name} ({$worker->reference}) : {$worker->refresh()->verification_status->label()}.");

        return self::SUCCESS;
    }
}
