<?php

namespace App\Support\Matching;

use App\Enums\AvailabilityStatus;
use App\Enums\MatchingRunType;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Enums\WorkerReservationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\MatchingWeightSet;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Worker;
use App\Support\Pricing\PricingRules;
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

    public function __construct(private PricingRules $pricing) {}

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

                $status = $workers->isEmpty() ? NeedStatus::Matching : NeedStatus::PropositionPromoteur;
                $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => $status->value, 'reason' => 'Simulation locale du matching', 'actor_type' => 'systeme']);
                $need->forceFill(['status' => $status])->save();
            }

            $site = $offer->site;

            if ($proposed->isEmpty()) {
                $this->moveSite($site, SiteStatus::Recherche);

                return null;
            }

            $daily = (int) $proposed->sum('promoter_daily_rate');
            $days = (int) $offer->start_date->diffInDays($offer->end_date) + 1;
            $total = $daily * $days;

            $proposal = $offer->proposals()->create([
                'reference' => sprintf('PR-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('proposal_reference_seq')")),
                'site_id' => $site->getKey(),
                'sequence' => ($offer->proposals()->max('sequence') ?? 0) + 1,
                'status' => ProposalStatus::AValider,
                'requested_count' => $requested,
                'proposed_count' => $proposed->count(),
                'is_partial' => $proposed->count() < $requested,
                'daily_amount' => $daily,
                'days' => $days,
                'total_amount' => $total,
                'deposit_amount' => $this->pricing->deposit($total),
                'expires_at' => now()->addHours((int) (Setting::query()->whereKey('proposals.validity_hours')->value('value') ?? 48)),
            ]);
            $proposal->suggestions()->saveMany($proposed);

            $this->moveSite($site, SiteStatus::Paiement);

            return $proposal;
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
