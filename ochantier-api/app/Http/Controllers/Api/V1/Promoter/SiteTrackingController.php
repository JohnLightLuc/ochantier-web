<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\WorkDayStatus;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Site;
use App\Models\WorkDay;
use App\Support\Promoter\SiteAttendance;
use App\Support\Promoter\SiteReplacements;
use App\Support\Promoter\SiteSteps;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Suivi du chantier confirmé : vue d’ensemble (indicateurs, pointage du jour, chronologie) et équipe.
 */
class SiteTrackingController extends Controller
{
    public function __construct(private SiteAttendance $attendance, private SiteSteps $steps, private SiteReplacements $replacements) {}

    /**
     * Indicateurs (durée, présence, étapes), pointage du jour et chronologie.
     */
    public function show(Site $site): JsonResponse
    {
        $today = $this->attendance->day($site, CarbonImmutable::today());
        $steps = $this->steps->summary($site);
        $missions = $site->missions()->whereNotIn('status', [MissionStatus::EnAttenteAcompte, MissionStatus::Annulee])->get();
        $validated = WorkDay::query()->whereIn('mission_id', $missions->modelKeys())->whereNotNull('validated_at')->get(['status']);
        $present = $validated->where('status', '!=', WorkDayStatus::Absent)->count();
        $start = $missions->min('planned_start_date');
        $end = $missions->max('planned_end_date');

        return response()->json(['data' => [
            'site' => ['id' => $site->id, 'reference' => $site->reference, 'title' => $site->label, 'status' => ['value' => $site->status->value, 'label' => $site->status->label()]],
            'kpis' => [
                'duration_days' => $start && $end ? (int) $start->diffInDays($end) + 1 : null,
                'presence_rate' => $validated->isEmpty() ? null : (int) round($present / $validated->count() * 100),
                'steps_done' => $steps['done'],
                'steps_total' => $steps['total'],
                'validated_days' => (int) $missions->max('validated_days'),
                'calendar_progress' => $missions->isEmpty() ? 0 : (float) $missions->max('calendar_progress'),
            ],
            'attendance' => collect($today)->only(['date', 'label', 'is_open', 'opens_on', 'closed_message', 'stats', 'amount', 'validated', 'can_validate', 'validation_hint'])->all(),
            'chronology' => collect($steps)->except('steps')->all(),
            'team_count' => $this->members($site)->count(),
        ]]);
    }

    /**
     * « Équipe » : ouvriers affectés (y compris les remplaçants qui arrivent), avec le remplacement en cours.
     */
    public function team(Site $site): JsonResponse
    {
        $members = $this->members($site)->map(function (Assignment $assignment): array {
            $open = $this->replacements->open($assignment);

            return [
                ...$this->attendance->member($assignment),
                'status' => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
                'is_upcoming' => $assignment->planned_start_date->isFuture(),
                'replacement' => $open ? ['id' => $open->id, 'status' => ['value' => $open->status->value, 'label' => $open->status->label()]] : null,
                'can_replace' => $open === null && $assignment->planned_end_date->greaterThanOrEqualTo(CarbonImmutable::tomorrow()),
            ];
        });

        return response()->json(['data' => $members->values()->all(), 'meta' => ['replacement_reasons' => $this->replacements->reasons()->all()]]);
    }

    /**
     * @return Collection<int, Assignment>
     */
    private function members(Site $site): Collection
    {
        return Assignment::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->whereIn('status', SiteAttendance::OPEN_MISSIONS))
            ->whereIn('status', [AssignmentStatus::Confirmee, AssignmentStatus::Active])
            ->with(SiteAttendance::ASSIGNMENT_WITH)
            ->get()
            ->pipe(fn (Collection $assignments) => $this->attendance->ordered($assignments));
    }
}
