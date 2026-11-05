<?php

namespace App\Support\Matching;

use App\Enums\ExclusionDecider;
use App\Enums\MatchingExclusionNature;
use App\Enums\MatchingRunType;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\MatchingDerogation;
use App\Models\MatchingExclusion;
use App\Models\MatchingRun;
use App\Models\MatchingWeightSet;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\Suggestion;
use App\Support\Admin\AdminAudit;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricingRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lancement du matching d’une offre par un opérateur : chaque besoin ouvert reçoit un classement. Une relance remplace
 * les suggestions encore non traitées et garde les ouvriers déjà contactés ou d’accord.
 */
class MatchingRunner
{
    public const OPEN_OFFERS = [OfferStatus::Soumise, OfferStatus::EnControle, OfferStatus::Publiee];

    public const OPEN_NEEDS = [NeedStatus::Soumis, NeedStatus::EnControle, NeedStatus::ACorriger, NeedStatus::Matching, NeedStatus::ContactOuvriers];

    /**
     * Suggestions gardées d’une relance à l’autre.
     */
    public const KEPT = [SuggestionStatus::Contacte, SuggestionStatus::Accepte, SuggestionStatus::ProposePromoteur, SuggestionStatus::Selectionne];

    public function __construct(private MatchingEngine $engine, private PricingRules $pricing, private ProposalPublisher $publisher) {}

    public function refusal(Offer $offer): ?string
    {
        return match (true) {
            $offer->offer_type === OfferType::Contractuelle => 'Les offres contractuelles ne passent pas par le matching.',
            ! in_array($offer->status, self::OPEN_OFFERS, true) => 'Ce besoin n’est pas ouvert à la recherche ('.mb_strtolower($offer->status->label()).').',
            $offer->proposals()->where('status', ProposalStatus::AValider)->exists() => 'Une proposition attend la décision du promoteur.',
            ! $offer->needs()->whereIn('status', self::OPEN_NEEDS)->exists() => 'Aucun poste à pourvoir sur ce besoin.',
            default => null,
        };
    }

    public function run(Offer $offer, Admin $admin, Request $request): void
    {
        $this->publisher->expireOverdue($offer);
        $offer = $offer->fresh();

        if ($refusal = $this->refusal($offer)) {
            throw ValidationException::withMessages(['offer' => $refusal]);
        }

        $weights = MatchingWeightSet::query()->where('is_active', true)->latest('effective_from')->first()
            ?? throw ValidationException::withMessages(['offer' => 'Aucun barème de matching actif.']);
        $limit = (int) (Setting::query()->whereKey('matching.profiles_shown')->value('value') ?? 8);
        $totals = ['candidates' => 0, 'excluded' => 0];

        DB::transaction(function () use ($offer, $admin, $weights, $limit, &$totals): void {
            foreach ($offer->needs()->whereIn('status', self::OPEN_NEEDS)->get() as $need) {
                /** @var Need $need */
                $counts = $this->runNeed($need, $weights, max($limit, $need->headcount * 2), $admin);
                $totals['candidates'] += $counts['candidates'];
                $totals['excluded'] += $counts['excluded'];
            }

            $site = $offer->site;

            if ($site->status === SiteStatus::Soumis) {
                $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::Recherche, 'reason' => 'Matching lancé', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
                $site->forceFill(['status' => SiteStatus::Recherche])->save();
            }
        });

        AdminAudit::record($admin, 'matching', 'matching.run', $request, $offer, null, $totals);
    }

    /**
     * @return array{candidates: int, excluded: int}
     */
    private function runNeed(Need $need, MatchingWeightSet $weights, int $limit, Admin $admin): array
    {
        $kept = $need->suggestions()->whereIn('status', self::KEPT)->pluck('worker_id')->flip();
        $need->suggestions()->where('status', SuggestionStatus::Suggere)->update(['status' => SuggestionStatus::Expire]);

        $evaluations = $this->engine->evaluate($need, $weights)->reject(fn (array $evaluation) => $kept->has($evaluation['worker']->id));
        $eligible = $evaluations->where('eligible', true)->take($limit)->values();
        $excluded = $evaluations->where('eligible', false)->values();

        /** @var MatchingRun $run */
        $run = $need->matchingRuns()->create([
            'weight_set_id' => $weights->getKey(),
            'run_type' => $need->matchingRuns()->exists() ? MatchingRunType::Relance : MatchingRunType::Initial,
            'triggered_by_admin_id' => $admin->getKey(),
            'candidate_count' => $eligible->count(),
        ]);

        foreach ($eligible as $rank => $evaluation) {
            $rate = (int) $evaluation['worker']->estimated_daily_rate;

            $need->suggestions()->create([
                'matching_run_id' => $run->getKey(),
                'worker_id' => $evaluation['worker']->getKey(),
                'rank' => $rank + 1,
                'score' => $evaluation['score'],
                'score_factors' => array_intersect_key($evaluation, array_flip(['level', 'requested', 'criteria', 'warnings'])),
                'distance_km' => $evaluation['distance_km'],
                'worker_daily_rate' => $rate,
                'promoter_daily_rate' => $this->pricing->promoterDailyRate($rate),
                'net_daily_rate' => $rate,
                'status' => SuggestionStatus::Suggere,
            ]);
        }

        foreach ($excluded as $evaluation) {
            foreach ($evaluation['exclusions'] as $item) {
                MatchingExclusion::query()->create([
                    'need_id' => $need->getKey(),
                    'worker_id' => $evaluation['worker']->getKey(),
                    'matching_run_id' => $run->getKey(),
                    'nature' => $item['absolute'] ? MatchingExclusionNature::Reglementaire : MatchingExclusionNature::Operationnelle,
                    'criterion' => $item['criterion'],
                    'comment' => $item['expected'].' : '.$item['found'],
                    'decided_by_type' => ExclusionDecider::Systeme,
                ]);
            }
        }

        if ($need->status !== NeedStatus::Matching) {
            $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::Matching->value, 'reason' => 'Matching lancé', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $need->forceFill(['status' => NeedStatus::Matching])->save();
        }

        return ['candidates' => $eligible->count(), 'excluded' => $excluded->count()];
    }

    /**
     * Résultats par besoin : dernier calcul, suggestions en cours et exclusions de ce calcul.
     *
     * @return list<array<string, mixed>>
     */
    public function results(Offer $offer, Admin $admin): array
    {
        $staleAfter = (int) (Setting::query()->whereKey('matching.obsolete_after_days')->value('value') ?? 3);

        return $offer->needs()->with(['trade', 'specialty'])->orderBy('reference')->get()->map(function (Need $need) use ($staleAfter, $admin) {
            $run = $need->matchingRuns()->with('triggeredByAdmin')->latest('executed_at')->latest('id')->first();

            return [
                'id' => $need->id,
                'reference' => $need->reference,
                'trade' => $need->trade?->label,
                'specialty' => $need->specialty?->label,
                'headcount' => $need->headcount,
                'min_experience_years' => $need->min_experience_years,
                'daily_budget' => $need->indicative_daily_budget,
                'status' => ['value' => $need->status->value, 'label' => $need->status->label()],
                'last_run' => $run ? ['at' => $run->executed_at?->toIso8601String(), 'by' => $run->triggeredByAdmin?->fullName(), 'type' => $run->run_type?->value, 'stale' => $run->executed_at?->lt(now()->subDays($staleAfter)) ?? false] : null,
                'suggestions' => $need->suggestions()->whereNotIn('status', [SuggestionStatus::Expire, SuggestionStatus::Ecarte])->with(['worker.workerTrades.trade', 'worker.residenceCommune', 'workerOfferContacts', 'workerResponses', 'need'])->orderBy('rank')->orderByDesc('score')->get()->map(fn (Suggestion $suggestion) => $this->suggestion($suggestion))->values()->all(),
                'exclusions' => $run ? $this->exclusions($need, $run, $admin) : [],
            ];
        })->all();
    }

    /**
     * Exclusions du dernier calcul et écarts manuels du besoin, avec leur dérogation.
     *
     * @return list<array<string, mixed>>
     */
    private function exclusions(Need $need, MatchingRun $run, Admin $admin): array
    {
        $exclusions = MatchingExclusion::query()
            ->where('need_id', $need->getKey())
            ->where(fn ($query) => $query->where('matching_run_id', $run->getKey())->orWhere('nature', MatchingExclusionNature::Manuelle))
            ->with('worker')->oldest()->oldest('id')->get();
        $manual = $exclusions->where('nature', MatchingExclusionNature::Manuelle)->pluck('worker_id')->flip();
        $blocked = $exclusions->where('nature', MatchingExclusionNature::Reglementaire)->pluck('worker_id')->flip();
        $derogations = MatchingDerogation::query()->where('need_id', $need->getKey())->whereNull('revoked_at')->with(['requestedByAdmin', 'approvedByAdmin'])->get();

        return $exclusions
            // Un écart manuel remplace le motif « a refusé ou a été écarté » que le calcul suivant déduit de lui.
            ->reject(fn (MatchingExclusion $exclusion) => $exclusion->nature !== MatchingExclusionNature::Manuelle && $manual->has($exclusion->worker_id))
            ->map(function (MatchingExclusion $exclusion) use ($derogations, $blocked, $admin) {
                $derogation = $derogations->first(fn (MatchingDerogation $item) => $item->worker_id === $exclusion->worker_id && $item->criterion === $exclusion->criterion);

                return [
                    'id' => $exclusion->id,
                    'worker' => ['id' => $exclusion->worker_id, 'full_name' => trim($exclusion->worker->first_name.' '.$exclusion->worker->last_name), 'reference' => $exclusion->worker->reference],
                    'nature' => ['value' => $exclusion->nature->value, 'label' => $exclusion->nature->label()],
                    'criterion' => $exclusion->criterion,
                    'comment' => $exclusion->comment,
                    'can_request_derogation' => $exclusion->nature === MatchingExclusionNature::Operationnelle && ! $blocked->has($exclusion->worker_id) && $derogation === null,
                    'can_lift' => $exclusion->nature === MatchingExclusionNature::Manuelle,
                    'derogation' => $derogation ? $this->derogation($derogation, $admin) : null,
                ];
            })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function derogation(MatchingDerogation $derogation, Admin $admin): array
    {
        return [
            'id' => $derogation->id,
            'criterion' => $derogation->criterion,
            'justification' => $derogation->justification,
            'state' => $derogation->approved_at ? 'approuvee' : 'en_attente',
            'requested_by' => $derogation->requestedByAdmin?->fullName(),
            'approved_by' => $derogation->approvedByAdmin?->fullName(),
            'can_decide' => $derogation->approved_at === null && $derogation->requested_by_admin_id !== $admin->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestion(Suggestion $suggestion): array
    {
        $worker = $suggestion->worker;
        $factors = $suggestion->score_factors ?? [];

        return [
            'id' => $suggestion->id,
            'rank' => $suggestion->rank,
            'score' => (float) $suggestion->score,
            'level' => $factors['level'] ?? null,
            'requested' => (bool) ($factors['requested'] ?? false),
            'status' => ['value' => $suggestion->status->value, 'label' => $suggestion->status->label()],
            'contact' => ($contact = $suggestion->workerOfferContacts->sortByDesc('contacted_at')->first()) ? ['channel' => $contact->channel->label(), 'at' => $contact->contacted_at?->toIso8601String()] : null,
            'response' => ($response = $suggestion->workerResponses->sortByDesc('responded_at')->first()) ? ['decision' => ['value' => $response->decision->value, 'label' => $response->decision->label()], 'conditions' => $response->conditions, 'net_daily_rate' => $response->net_daily_rate_shown, 'at' => $response->responded_at?->toIso8601String()] : null,
            'worker' => [
                'id' => $worker->id,
                'reference' => $worker->reference,
                'full_name' => trim($worker->first_name.' '.$worker->last_name),
                'phone' => PhoneNumber::of($worker)?->formatted(),
                'commune' => $worker->residenceCommune?->name,
                'rating' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null,
                'experience_years' => $worker->workerTrades->firstWhere('trade_id', $suggestion->need->trade_id)?->years_experience,
            ],
            'distance_km' => $suggestion->distance_km !== null ? (float) $suggestion->distance_km : null,
            'worker_daily_rate' => $suggestion->worker_daily_rate,
            'promoter_daily_rate' => $suggestion->promoter_daily_rate,
            'criteria' => $factors['criteria'] ?? [],
            'warnings' => $factors['warnings'] ?? [],
        ];
    }
}
