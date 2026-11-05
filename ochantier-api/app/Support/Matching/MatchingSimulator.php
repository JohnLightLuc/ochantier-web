<?php

namespace App\Support\Matching;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\MatchingRunType;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProposalStatus;
use App\Enums\ReplacementPromoterDecision;
use App\Enums\ReplacementStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Enums\UnavailabilityRequestStatus;
use App\Enums\WorkerReservationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\MatchingWeightSet;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Replacement;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\Worker;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Simulation locale du parcours Back office d’une offre publiée, pour tester l’application Promoteur de bout en
 * bout : contrôle de l’offre, matching des besoins, réponse des ouvriers et publication d’une proposition d’équipe.
 *
 * Les ouvriers demandés par le promoteur sont proposés en premier, puis les ouvriers validés et disponibles du
 * métier, par note. Hors production uniquement.
 */
class MatchingSimulator
{
    /**
     * Besoins encore à pourvoir (sans proposition en attente).
     */
    private const OPEN_NEEDS = [NeedStatus::Soumis, NeedStatus::EnControle, NeedStatus::ACorriger, NeedStatus::Matching, NeedStatus::ContactOuvriers];

    public function __construct(private PricingRules $pricing, private ProposalPublisher $publisher) {}

    /**
     * Offres en attente d’une proposition : publiées par un promoteur, journalières, sans proposition à valider.
     *
     * @return Builder<Offer>
     */
    public function pendingOffers(): Builder
    {
        return Offer::query()
            ->whereIn('status', [OfferStatus::Soumise, OfferStatus::EnControle, OfferStatus::Publiee])
            ->where('offer_type', '!=', OfferType::Contractuelle)
            ->whereDoesntHave('proposals', fn (Builder $query) => $query->where('status', ProposalStatus::AValider))
            ->whereHas('needs', fn (Builder $query) => $query->whereIn('status', self::OPEN_NEEDS));
    }

    /**
     * Publie l’offre, cherche des ouvriers pour chaque besoin ouvert et présente au promoteur ceux qui ont
     * « accepté ». Avec $missing, ce nombre d’ouvriers manque volontairement pour obtenir une proposition partielle.
     *
     * Renvoie null si aucun ouvrier n’est disponible : l’offre reste en recherche.
     */
    public function simulate(Offer $offer, int $missing = 0): ?Proposal
    {
        if ($offer->offer_type === OfferType::Contractuelle) {
            throw new RuntimeException('Les réponses des entreprises aux offres contractuelles ne sont pas encore prises en charge.');
        }

        $weights = MatchingWeightSet::query()->where('is_active', true)->latest('effective_from')->first()
            ?? throw new RuntimeException('Aucun barème de matching actif : lancez php artisan db:seed.');

        return DB::transaction(function () use ($offer, $missing, $weights): ?Proposal {
            if ($offer->status !== OfferStatus::Publiee) {
                $from = $offer->status->value;
                $offer->forceFill(['status' => OfferStatus::Publiee, 'published_at' => now()])->save();
                $offer->statusHistories()->create(['from_status' => $from, 'to_status' => OfferStatus::Publiee->value, 'reason' => 'Simulation locale du contrôle Back office', 'actor_type' => 'systeme']);
            }

            $needs = $offer->needs()->whereIn('status', self::OPEN_NEEDS)->with('requestedWorkers')->get();
            $proposed = collect();
            $requested = 0;

            foreach ($needs as $index => $need) {
                $requested += $need->headcount;
                $take = max(0, $need->headcount - ($index === 0 ? $missing : 0));
                $workers = $this->candidates($need)->take($take);
                $run = $need->matchingRuns()->create([
                    'weight_set_id' => $weights->getKey(),
                    'run_type' => $need->matchingRuns()->exists() ? MatchingRunType::Relance : MatchingRunType::Initial,
                    'filters' => ['simulation' => true],
                    'candidate_count' => $workers->count(),
                ]);

                foreach ($workers->values() as $rank => $worker) {
                    $proposed->push($need->suggestions()->create([
                        'matching_run_id' => $run->getKey(),
                        'worker_id' => $worker->getKey(),
                        'rank' => $rank + 1,
                        'score' => 95 - $rank * 3,
                        'score_factors' => ['simulation' => true, 'demande_promoteur' => $need->requestedWorkers->contains('worker_id', $worker->getKey())],
                        'worker_daily_rate' => $worker->estimated_daily_rate,
                        'promoter_daily_rate' => $this->pricing->promoterDailyRate($worker->estimated_daily_rate),
                        'net_daily_rate' => $worker->estimated_daily_rate,
                        'status' => SuggestionStatus::ProposePromoteur,
                        'published_to_promoter_at' => now(),
                    ]));
                }

                $status = NeedStatus::Matching;
                $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => $status->value, 'reason' => 'Simulation locale du matching', 'actor_type' => 'systeme']);
                $need->forceFill(['status' => $status])->save();
            }

            $site = $offer->site;

            if ($proposed->isEmpty()) {
                $this->moveSite($site, SiteStatus::Recherche);

                return null;
            }

            $proposed->each(fn (Suggestion $suggestion) => $suggestion->forceFill(['status' => SuggestionStatus::Accepte])->save());

            return $this->publisher->publish($offer, $proposed, null, 'Simulation locale de la proposition');
        });
    }

    /**
     * Remplacements demandés par un promoteur, en attente de remplaçants.
     *
     * @return Builder<Replacement>
     */
    public function pendingReplacements(): Builder
    {
        return Replacement::query()->whereIn('status', [ReplacementStatus::Signale, ReplacementStatus::Recherche]);
    }

    /**
     * Propose au promoteur jusqu’à trois remplaçants du métier, libres de la relève à la fin de la mission et dont le
     * tarif promoteur ne dépasse pas celui de l’affectation d’origine. Renvoie le nombre de remplaçants proposés.
     */
    public function simulateReplacement(Replacement $replacement): int
    {
        $weights = MatchingWeightSet::query()->where('is_active', true)->latest('effective_from')->first()
            ?? throw new RuntimeException('Aucun barème de matching actif : lancez php artisan db:seed.');

        return DB::transaction(function () use ($replacement, $weights): int {
            $criteria = $replacement->requested_criteria;
            $original = $replacement->originalAssignment()->with('need')->firstOrFail();
            $period = sprintf('[%s,%s]', $criteria['from_date'], $criteria['to_date']);
            $excluded = Suggestion::query()->whereHas('matchingRun', fn (Builder $query) => $query->where('replacement_id', $replacement->getKey()))->pluck('worker_id')
                ->merge($original->mission->site->missions()->with('assignments')->get()->flatMap->assignments->pluck('worker_id'));

            $workers = Worker::query()
                ->where('verification_status', WorkerVerificationStatus::Valide)
                ->where('availability_status', AvailabilityStatus::Disponible)
                ->whereNull('merged_into_worker_id')
                ->whereNotNull('estimated_daily_rate')
                ->whereNotIn('id', $excluded->unique()->all())
                ->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $criteria['trade_id']))
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('assignments')
                    ->whereColumn('assignments.worker_id', 'workers.id')
                    ->whereIn('assignments.status', [AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value])
                    ->whereRaw('assignments.period && ?::daterange', [$period]))
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('worker_reservations')
                    ->whereColumn('worker_reservations.worker_id', 'workers.id')
                    ->where('worker_reservations.status', WorkerReservationStatus::Active->value)
                    ->whereRaw('worker_reservations.period && ?::daterange', [$period]))
                ->get()
                ->filter(fn (Worker $worker) => $criteria['max_promoter_daily_rate'] === null || $this->pricing->promoterDailyRate($worker->estimated_daily_rate) <= $criteria['max_promoter_daily_rate'])
                ->sortByDesc(fn (Worker $worker) => (float) $worker->rating_avg)
                ->take(3)
                ->values();

            $run = $original->need->matchingRuns()->create([
                'weight_set_id' => $weights->getKey(),
                'run_type' => MatchingRunType::Remplacement,
                'replacement_id' => $replacement->getKey(),
                'filters' => ['simulation' => true, ...$criteria],
                'candidate_count' => $workers->count(),
            ]);

            foreach ($workers as $rank => $worker) {
                $run->suggestions()->create([
                    'need_id' => $original->need_id,
                    'worker_id' => $worker->getKey(),
                    'rank' => $rank + 1,
                    'score' => 95 - $rank * 3,
                    'score_factors' => ['simulation' => true, 'remplacement' => true],
                    'worker_daily_rate' => $worker->estimated_daily_rate,
                    'promoter_daily_rate' => $original->promoter_daily_rate,
                    'net_daily_rate' => $worker->estimated_daily_rate,
                    'status' => SuggestionStatus::ProposePromoteur,
                    'published_to_promoter_at' => now(),
                ]);
            }

            if ($workers->isNotEmpty()) {
                $replacement->statusHistories()->create(['from_status' => $replacement->status->value, 'to_status' => ReplacementStatus::Propose->value, 'reason' => 'Simulation locale du matching', 'actor_type' => 'systeme']);
                $replacement->forceFill(['status' => ReplacementStatus::Propose, 'promoter_decision' => ReplacementPromoterDecision::EnAttente, 'promoter_decision_reason' => null, 'promoter_decided_at' => null])->save();
                $replacement->unavailabilityRequest->update(['status' => UnavailabilityRequestStatus::RemplacantPropose]);
                app(PromoterNotifier::class)->replacementsProposed($replacement, $workers->count());
            }

            return $workers->count();
        });
    }

    /**
     * Ouvriers validés et disponibles du métier, libres sur la période du besoin et pas déjà écartés pour ce
     * besoin : ceux demandés par le promoteur d’abord, puis par note.
     *
     * @return Collection<int, Worker>
     */
    private function candidates(Need $need): Collection
    {
        $requested = $need->requestedWorkers->pluck('worker_id')->all();
        $period = sprintf('[%s,%s]', $need->start_date->toDateString(), ($need->end_date ?? $need->start_date)->toDateString());

        return Worker::query()
            ->where('verification_status', WorkerVerificationStatus::Valide)
            ->where('availability_status', AvailabilityStatus::Disponible)
            ->whereNull('merged_into_worker_id')
            ->whereNotNull('estimated_daily_rate')
            ->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $need->trade_id))
            ->whereDoesntHave('suggestions', fn (Builder $query) => $query->where('need_id', $need->getKey())->whereIn('status', [SuggestionStatus::Ecarte, SuggestionStatus::ProposePromoteur, SuggestionStatus::Selectionne]))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('worker_reservations')
                ->whereColumn('worker_reservations.worker_id', 'workers.id')
                ->where('worker_reservations.status', WorkerReservationStatus::Active->value)
                ->whereRaw('worker_reservations.period && ?::daterange', [$period]))
            ->get()
            ->sortBy([
                fn (Worker $a, Worker $b) => in_array($b->getKey(), $requested, true) <=> in_array($a->getKey(), $requested, true),
                fn (Worker $a, Worker $b) => (float) $b->rating_avg <=> (float) $a->rating_avg,
            ])
            ->values();
    }

    /**
     * Le chantier suit sa première offre : « Recherche d’ouvriers en cours », puis « Ouvriers trouvés ».
     */
    private function moveSite(Site $site, SiteStatus $status): void
    {
        if (! in_array($site->status, [SiteStatus::Soumis, SiteStatus::Recherche, SiteStatus::Paiement], true) || $site->status === $status) {
            return;
        }

        $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => $status, 'reason' => 'Simulation locale du matching', 'actor_type' => 'systeme']);
        $site->forceFill(['status' => $status])->save();
    }
}
