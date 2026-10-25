<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\SiteStatus;
use App\Models\Assignment;
use App\Models\Mission;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $closed = DB::transaction(fn (): int => $this->close($site));

        $this->info("{$site->label} : chantier terminé, {$closed} mission(s) clôturée(s).");

        return self::SUCCESS;
    }

    private function close(Site $site): int
    {
        $now = now();
        $missions = $site->missions()->whereIn('status', [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Suspendue])->get();

        foreach ($missions as $mission) {
            /** @var Mission $mission */
            $assignments = $mission->assignments()->whereIn('status', [AssignmentStatus::Confirmee, AssignmentStatus::Active])->get();

            foreach ($assignments as $assignment) {
                /** @var Assignment $assignment */
                $assignment->statusHistories()->create(['from_status' => $assignment->status->value, 'to_status' => AssignmentStatus::Terminee->value, 'reason' => self::REASON, 'actor_type' => 'systeme']);
                $assignment->update([
                    'status' => AssignmentStatus::Terminee,
                    'actual_start_at' => $assignment->actual_start_at ?? $assignment->planned_start_date->min($now),
                    'actual_end_at' => $now,
                    'end_reason' => self::REASON,
                ]);
            }

            $mission->statusHistories()->create(['from_status' => $mission->status->value, 'to_status' => MissionStatus::Terminee->value, 'reason' => self::REASON, 'actor_type' => 'systeme']);
            $mission->update([
                'status' => MissionStatus::Terminee,
                'actual_start_date' => $mission->actual_start_date ?? $mission->planned_start_date->min($now),
                'actual_end_date' => $now->toDateString(),
                'closed_at' => $now,
            ]);
        }

        $site->statusHistories()->create(['from_status' => $site->status->value, 'to_status' => SiteStatus::Termine->value, 'reason' => self::REASON, 'actor_type' => 'systeme']);
        $site->update(['status' => SiteStatus::Termine, 'closed_at' => $now]);

        return $missions->count();
    }
}
