<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\SiteStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\Mission;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d’un chantier : missions et affectations terminées, chantier « Terminé ». Le promoteur peut alors noter son
 * équipe et, s’il est abonné, consulter le rapport de fin de chantier.
 */
class SiteClosure
{
    public function __construct(private PromoterNotifier $notifier, private PromoterSubscriptions $subscriptions) {}

    /**
     * @return int Nombre de missions clôturées.
     */
    public function close(Site $site, string $reason, ?Admin $admin = null): int
    {
        $now = now();
        $actor = $admin ? ['actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()] : ['actor_type' => 'systeme', 'actor_id' => null];

        $count = DB::transaction(function () use ($site, $reason, $now, $actor, $admin): int {
            $missions = $site->missions()->whereIn('status', [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Suspendue])->get();

            foreach ($missions as $mission) {
                /** @var Mission $mission */
                foreach ($mission->assignments()->whereIn('status', [AssignmentStatus::Confirmee, AssignmentStatus::Active])->get() as $assignment) {
                    /** @var Assignment $assignment */
                    $assignment->statusHistories()->create(['from_status' => $assignment->status->value, 'to_status' => AssignmentStatus::Terminee->value, 'reason' => $reason, ...$actor]);
                    $assignment->update([
                        'status' => AssignmentStatus::Terminee,
                        'actual_start_at' => $assignment->actual_start_at ?? $assignment->planned_start_date->min($now),
                        'actual_end_at' => $now,
                        'end_reason' => $reason,
                    ]);
                }

                $mission->statusHistories()->create(['from_status' => $mission->status->value, 'to_status' => MissionStatus::Terminee->value, 'reason' => $reason, ...$actor]);
                $mission->update([
                    'status' => MissionStatus::Terminee,
                    'actual_start_date' => $mission->actual_start_date ?? $mission->planned_start_date->min($now),
                    'actual_end_date' => $now->toDateString(),
                    'closed_at' => $now,
                ]);
            }

            $site->statusHistories()->create(['from_status' => $site->status->value, 'to_status' => SiteStatus::Termine->value, 'reason' => $reason, ...$actor]);
            $site->update(['status' => SiteStatus::Termine, 'closed_at' => $now, 'closed_by_admin_id' => $admin?->getKey()]);

            return $missions->count();
        });

        $this->notifier->siteEnded($site->refresh(), $this->subscriptions->isSubscribed($site->promoterProfile));

        return $count;
    }
}
