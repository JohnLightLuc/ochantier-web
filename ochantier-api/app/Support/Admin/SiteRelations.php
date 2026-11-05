<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteIncident;
use App\Models\SiteStatusHistory;
use App\Models\User;
use App\Support\Promoter\SiteAttendance;
use App\Support\Promoter\SiteReplacements;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Onglets d’un chantier dans le Back Office : équipe et pointages (lecture seule), incidents et historique, qui réunit
 * les changements de statut et les actions des administrateurs.
 */
class SiteRelations
{
    private const ACTIONS = [
        'site.suspended' => 'a suspendu le chantier',
        'site.resumed' => 'a repris le chantier',
        'site.cancelled' => 'a annulé le chantier',
        'site.closed' => 'a clôturé le chantier',
        'site.archived' => 'a archivé le chantier',
        'site.incident_declared' => 'a déclaré un incident',
        'site.incident_started' => 'a pris en charge un incident',
        'site.incident_resolved' => 'a résolu un incident',
    ];

    public function __construct(private SiteAttendance $attendance, private SiteReplacements $replacements) {}

    /**
     * @return array<string, mixed>
     */
    public function team(Site $site): array
    {
        $assignments = Assignment::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->whereNotIn('status', [MissionStatus::EnAttenteAcompte, MissionStatus::Annulee]))
            ->whereNotIn('status', [AssignmentStatus::Annulee])
            ->with(SiteAttendance::ASSIGNMENT_WITH)
            ->get();

        $today = $this->attendance->day($site, CarbonImmutable::today());
        $history = $this->attendance->history($site);

        return [
            'members' => $this->attendance->ordered($assignments)->map(function (Assignment $assignment) {
                $open = $this->replacements->open($assignment);

                return [
                    ...$this->attendance->member($assignment),
                    'status' => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
                    'replacement' => $open ? ['value' => $open->status->value, 'label' => $open->status->label()] : null,
                ];
            })->values()->all(),
            'today' => ['label' => $today['label'], 'is_open' => $today['is_open'], 'closed_message' => $today['closed_message'], 'stats' => $today['stats'], 'validated' => $today['validated']],
            'days' => array_map(fn (array $day) => array_intersect_key($day, array_flip(['date', 'label', 'validated', 'present', 'total', 'presence_rate'])), array_slice($history['data'], 0, 30)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function incidents(Site $site): array
    {
        return $site->incidents()->with('resolvedByAdmin')->latest('occurred_at')->latest('id')->get()->map(fn (SiteIncident $incident) => [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'type' => ['value' => $incident->incident_type->value, 'label' => $incident->incident_type->label()],
            'severity' => ['value' => $incident->severity->value, 'label' => $incident->severity->label()],
            'status' => ['value' => $incident->status->value, 'label' => $incident->status->label()],
            'description' => $incident->description,
            'resolution' => $incident->resolution,
            'resolved_by' => $incident->resolvedByAdmin?->fullName(),
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function history(Site $site, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $histories = $site->statusHistories()->get();
        $users = User::query()->whereIn('id', $histories->where('actor_type', (new User)->getMorphClass())->pluck('actor_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $statuses = $histories
            // Les décisions du Back Office figurent déjà dans le journal d’audit, avec leur motif.
            ->reject(fn (SiteStatusHistory $history) => $history->actor_type === (new Admin)->getMorphClass())
            ->map(fn (SiteStatusHistory $history) => [
                'id' => 'statut:'.$history->id,
                'origin' => $history->actor_type === (new User)->getMorphClass() ? 'promoteur' : 'systeme',
                'author' => ($user = $users->get($history->actor_id)) ? trim($user->first_name.' '.$user->last_name) : 'Système',
                'what' => 'a changé le statut',
                'detail' => $history->reason,
                'from' => $history->from_status?->label(),
                'to' => $history->to_status->label(),
                'at' => $history->created_at,
            ]);

        $audits = AuditLog::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->getKey())
            ->whereIn('action', array_keys(self::ACTIONS))
            ->with('actor')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => 'audit:'.$log->id,
                'origin' => 'admin',
                'author' => $log->actor instanceof Admin ? $log->actor->fullName() : 'Administrateur',
                'what' => self::ACTIONS[$log->action],
                'detail' => $log->after_values['reason'] ?? ($log->after_values['reference'] ?? null),
                'from' => null,
                'to' => null,
                'at' => $log->created_at,
            ]);

        $events = $statuses->toBase()->concat($audits->toBase())->sortByDesc('at')->values();

        return new LengthAwarePaginator(
            $events->forPage($page, $perPage)->map(fn (array $event) => [...$event, 'at' => $event['at']?->toIso8601String()])->values()->all(),
            $events->count(),
            $perPage,
            $page,
        );
    }
}
