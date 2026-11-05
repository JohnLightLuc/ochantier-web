<?php

namespace App\Support\Matching;

use App\Enums\AssignmentStatus;
use App\Enums\MatchingRunType;
use App\Enums\ReplacementPromoterDecision;
use App\Enums\ReplacementStatus;
use App\Enums\SuggestionStatus;
use App\Enums\UnavailabilityRequestStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\MatchingRun;
use App\Models\MatchingWeightSet;
use App\Models\Replacement;
use App\Models\Setting;
use App\Models\Suggestion;
use App\Support\Admin\AdminAudit;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\SiteReplacements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recherche d’un remplaçant par le Back Office : classement par le moteur sur la période restante, accord des ouvriers
 * (WorkerConsent), puis envoi au promoteur, qui choisit dans son application. Le tarif promoteur reste celui de
 * l’affectation d’origine : un ouvrier plus cher n’est pas proposable.
 */
class ReplacementSearch
{
    /**
     * Remplaçants envoyés au promoteur au plus.
     */
    public const SENT = 3;

    private const SEARCHING = [ReplacementStatus::Signale, ReplacementStatus::Recherche];

    private const KEPT = [SuggestionStatus::Contacte, SuggestionStatus::Accepte];

    public function __construct(private MatchingEngine $engine, private MatchingRunner $runner, private PricingRules $pricing, private SiteReplacements $replacements, private PromoterNotifier $notifier) {}

    public function refusal(Replacement $replacement): ?string
    {
        $original = $replacement->originalAssignment;

        return match (true) {
            $replacement->status === ReplacementStatus::Propose => 'Des remplaçants attendent la décision du promoteur.',
            ! in_array($replacement->status, self::SEARCHING, true) => 'Ce remplacement est clos.',
            ! in_array($original->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true) => 'Cet ouvrier ne fait plus partie de l’équipe.',
            CarbonImmutable::tomorrow()->greaterThan($original->planned_end_date) => 'La mission se termine aujourd’hui : aucun remplacement n’est possible.',
            default => null,
        };
    }

    public function run(Replacement $replacement, Admin $admin, Request $request): void
    {
        $this->ensureOpen($replacement);

        $weights = MatchingWeightSet::query()->where('is_active', true)->latest('effective_from')->first()
            ?? throw ValidationException::withMessages(['replacement' => 'Aucun barème de matching actif.']);
        $limit = (int) (Setting::query()->whereKey('matching.profiles_shown')->value('value') ?? 8);
        $totals = [];

        DB::transaction(function () use ($replacement, $admin, $weights, $limit, &$totals): void {
            $original = $replacement->originalAssignment()->with(['need', 'mission'])->firstOrFail();
            $from = $this->replacements->handoverDate($original);
            $to = CarbonImmutable::parse($original->planned_end_date);
            $ceiling = (int) $original->promoter_daily_rate;

            $this->suggestions($replacement)->where('status', SuggestionStatus::Suggere)->update(['status' => SuggestionStatus::Expire]);

            $skipped = $original->mission->assignments()->pluck('worker_id')->merge($this->suggestions($replacement)->whereIn('status', self::KEPT)->pluck('worker_id'))->flip();
            $evaluations = $this->engine->evaluate($original->need, $weights, $from, $to)
                ->reject(fn (array $evaluation) => $skipped->has($evaluation['worker']->id))
                ->map(fn (array $evaluation) => $this->capped($evaluation, $ceiling));
            $eligible = $evaluations->where('eligible', true)->take($limit)->values();
            $excluded = $evaluations->where('eligible', false)->values();

            /** @var MatchingRun $run */
            $run = $original->need->matchingRuns()->create([
                'weight_set_id' => $weights->getKey(),
                'run_type' => MatchingRunType::Remplacement,
                'replacement_id' => $replacement->getKey(),
                'filters' => ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'max_promoter_daily_rate' => $ceiling],
                'triggered_by_admin_id' => $admin->getKey(),
                'candidate_count' => $eligible->count(),
            ]);

            foreach ($eligible as $rank => $evaluation) {
                $rate = (int) $evaluation['worker']->estimated_daily_rate;

                $run->suggestions()->create([
                    'need_id' => $original->need_id,
                    'worker_id' => $evaluation['worker']->getKey(),
                    'rank' => $rank + 1,
                    'score' => $evaluation['score'],
                    'score_factors' => array_intersect_key($evaluation, array_flip(['level', 'requested', 'criteria', 'warnings'])),
                    'distance_km' => $evaluation['distance_km'],
                    'worker_daily_rate' => $rate,
                    'promoter_daily_rate' => $ceiling,
                    'net_daily_rate' => $rate,
                    'status' => SuggestionStatus::Suggere,
                ]);
            }

            $this->runner->recordExclusions($original->need, $run, $excluded);
            $replacement->update(['requested_criteria' => [...$replacement->requested_criteria, 'from_date' => $from->toDateString()]]);
            $totals = ['candidates' => $eligible->count(), 'excluded' => $excluded->count()];
        });

        AdminAudit::record($admin, 'missions', 'replacement.search_run', $request, $replacement, null, $totals);
    }

    /**
     * Envoie au promoteur les ouvriers d’accord encore libres, par rang.
     */
    public function send(Replacement $replacement, Admin $admin, Request $request): void
    {
        $this->ensureOpen($replacement);
        $chosen = $this->sendable($replacement);

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages(['replacement' => 'Aucun ouvrier disponible n’a encore donné son accord.']);
        }

        DB::transaction(function () use ($replacement, $chosen, $admin): void {
            Suggestion::query()->whereIn('id', $chosen->pluck('id'))->update(['status' => SuggestionStatus::ProposePromoteur, 'published_to_promoter_at' => now()]);
            $replacement->statusHistories()->create(['from_status' => $replacement->status->value, 'to_status' => ReplacementStatus::Propose->value, 'reason' => 'Remplaçants envoyés par le Back Office', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $replacement->forceFill(['status' => ReplacementStatus::Propose, 'promoter_decision' => ReplacementPromoterDecision::EnAttente, 'promoter_decision_reason' => null, 'promoter_decided_at' => null])->save();
            $replacement->unavailabilityRequest->update(['status' => UnavailabilityRequestStatus::RemplacantPropose]);
            $this->notifier->replacementsProposed($replacement, $chosen->count());
        });

        AdminAudit::record($admin, 'missions', 'replacement.sent', $request, $replacement, null, ['proposed' => $chosen->count()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Replacement $replacement): array
    {
        $run = MatchingRun::query()->where('replacement_id', $replacement->getKey())->with('triggeredByAdmin')->latest('executed_at')->latest('id')->first();
        $refusal = $this->refusal($replacement);

        return [
            'id' => $replacement->id,
            'status' => ['value' => $replacement->status->value, 'label' => $replacement->status->label()],
            'refusal' => $refusal,
            'can_run' => $refusal === null,
            'can_send' => $refusal === null && $this->sendable($replacement)->isNotEmpty(),
            'promoter_refusal' => $replacement->promoter_decision === ReplacementPromoterDecision::Refuse ? ($replacement->promoter_decision_reason ?: 'Remplaçants refusés par le promoteur.') : null,
            'max_promoter_daily_rate' => $replacement->requested_criteria['max_promoter_daily_rate'] ?? null,
            'from_date' => $replacement->requested_criteria['from_date'] ?? null,
            'last_run' => $run ? ['at' => $run->executed_at?->toIso8601String(), 'by' => $run->triggeredByAdmin?->fullName(), 'excluded' => $run->matchingExclusions()->distinct()->count('worker_id')] : null,
            'suggestions' => $this->suggestions($replacement)->whereNotIn('status', [SuggestionStatus::Expire, SuggestionStatus::Ecarte])->with(MatchingRunner::SUGGESTION_WITH)->orderBy('rank')->orderByDesc('score')->get()
                ->map(fn (Suggestion $suggestion) => $this->runner->suggestion($suggestion))->values()->all(),
        ];
    }

    /**
     * Un ouvrier plus cher que le tarif figé n’est pas proposable : le promoteur ne paie pas de différence.
     *
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function capped(array $evaluation, int $ceiling): array
    {
        $rate = $this->pricing->promoterDailyRate($evaluation['worker']->estimated_daily_rate);

        if (! $evaluation['eligible'] || $rate <= $ceiling) {
            return $evaluation;
        }

        return [...$evaluation, 'eligible' => false, 'exclusions' => [['criterion' => 'cout', 'expected' => "Tarif promoteur d’au plus {$ceiling} FCFA", 'found' => "{$rate} FCFA", 'absolute' => false]]];
    }

    /**
     * @return Collection<int, Suggestion>
     */
    private function sendable(Replacement $replacement): Collection
    {
        $original = $replacement->originalAssignment;
        $period = sprintf('[%s,%s]', $this->replacements->handoverDate($original)->toDateString(), CarbonImmutable::parse($original->planned_end_date)->toDateString());

        return $this->suggestions($replacement)
            ->where('status', SuggestionStatus::Accepte)
            ->whereHas('worker', fn ($query) => $query->where('verification_status', WorkerVerificationStatus::Valide))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('assignments')
                ->whereColumn('assignments.worker_id', 'suggestions.worker_id')
                ->whereIn('assignments.status', [AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value])
                ->whereRaw('assignments.period && ?::daterange', [$period]))
            ->orderBy('rank')->orderByDesc('score')->take(self::SENT)->get();
    }

    /**
     * @return Builder<Suggestion>
     */
    private function suggestions(Replacement $replacement): Builder
    {
        return Suggestion::query()->whereHas('matchingRun', fn ($query) => $query->where('replacement_id', $replacement->getKey()));
    }

    private function ensureOpen(Replacement $replacement): void
    {
        if ($refusal = $this->refusal($replacement)) {
            throw ValidationException::withMessages(['replacement' => $refusal]);
        }
    }
}
