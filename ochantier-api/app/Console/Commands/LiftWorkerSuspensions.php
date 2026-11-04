<?php

namespace App\Console\Commands;

use App\Support\Admin\WorkerVerification;
use Illuminate\Console\Command;

/**
 * Lève les suspensions d’ouvriers arrivées à leur date de fin : l’ouvrier redevient validé et peut de nouveau être
 * proposé sur les chantiers.
 */
class LiftWorkerSuspensions extends Command
{
    protected $signature = 'ochantier:lift-worker-suspensions';

    protected $description = 'Lève les suspensions d’ouvriers arrivées à leur date de fin';

    public function handle(WorkerVerification $verification): int
    {
        $count = $verification->liftExpiredSuspensions();
        $this->info($count.' suspension'.($count > 1 ? 's levées' : ' levée').'.');

        return self::SUCCESS;
    }
}
