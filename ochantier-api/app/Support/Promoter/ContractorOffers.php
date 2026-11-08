<?php

namespace App\Support\Promoter;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorStatus;
use App\Enums\ContractPaymentMode;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\PaymentFrequency;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\PricingSnapshotKind;
use App\Enums\PricingVariable;
use App\Enums\SalaryPeriod;
use App\Enums\SiteStatus;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Mission;
use App\Models\Need;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\PricingParameter;
use App\Models\PricingSnapshot;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Trade;
use App\Models\User;
use App\Support\Media\MediaStore;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offres des entrepreneurs sur une offre contractuelle (« Recherche d’entrepreneurs en cours », « Offres
 * d’entrepreneurs », « Payer l’acompte »).
 *
 * Publiée, l’offre contractuelle part directement aux entrepreneurs (entreprises et indépendants ; les ouvriers ne
 * la reçoivent pas). Le promoteur voit les BEST_BIDS meilleures offres, classées par un score qui pondère la note
 * de l’entrepreneur, le montant, la durée et la distance au chantier (WEIGHTS), au prix promoteur : montant de
 * l’entrepreneur plus la commission Y. Il en choisit une, puis paie l’acompte (X du
 * total). Le forfait est alors porté par un besoin « Forfait entrepreneur » et sa mission, avec l’instantané
 * tarifaire et l’échéancier du solde (hebdomadaire ou par étape), comme une réservation d’ouvriers.
 */
class ContractorOffers
{
    /**
     * Offres affichées au promoteur.
     */
    public const BEST_BIDS = 3;

    /**
     * Métier technique du besoin qui porte le forfait (inactif, absent des listes).
     */
    public const TRADE = 'forfait_entrepreneur';

    /**
     * Pondérations par défaut du classement (points sur 100).
     *
     * @var array{note: int, montant: int, duree: int, distance: int}
     */
    public const WEIGHTS = ['note' => 40, 'montant' => 30, 'duree' => 15, 'distance' => 15];

    /**
     * Valeur d’un critère inconnu (entrepreneur sans avis, distance non calculable).
     */
    private const NEUTRAL = 0.5;

    /**
     * Offres encore en lice.
     */
    private const OPEN = [ContractorBidStatus::Proposee, ContractorBidStatus::Choisie];

    public function __construct(private PricingRules $pricing, private OfferDraft $drafts, private MediaStore $media) {}

    /**
     * Offre contractuelle publiée du chantier, en attente d’entrepreneur ou d’acompte.
     */
    public function openOffer(Site $site): ?Offer
    {
        return $site->offers()
            ->where('offer_type', OfferType::Contractuelle->value)
            ->where('status', OfferStatus::Publiee->value)
            ->latest('published_at')
            ->first();
    }

    /**
     * Offre d’un entrepreneur (une par entrepreneur et par offre, remplacée s’il répond de nouveau). La distance est
     * calculée entre l’entrepreneur et le chantier.
     */
    public function addBid(Offer $offer, Contractor $contractor, int $amount, int $durationDays, int $teamSize, ?string $message = null): ContractorBid
    {
        if ($offer->offer_type !== OfferType::Contractuelle || $offer->status !== OfferStatus::Publiee || $offer->contractor_bid_id) {
            throw ValidationException::withMessages(['offer' => 'Cette offre contractuelle ne reçoit plus d’offre.']);
        }

        if ($contractor->status !== ContractorStatus::Actif) {
            throw ValidationException::withMessages(['contractor' => 'Cet entrepreneur ne peut pas répondre aux offres.']);
        }

        $distance = DB::scalar('SELECT ST_Distance(c.location, s.location) / 1000 FROM contractors c, sites s WHERE c.id = ? AND s.id = ?', [$contractor->getKey(), $offer->site_id]);

        return $offer->contractorBids()->updateOrCreate(['contractor_id' => $contractor->getKey()], [
            'amount' => $amount,
            'duration_days' => $durationDays,
            'team_size' => $teamSize,
            'distance_km' => $distance !== null ? round((float) $distance, 1) : null,
            'message' => $message,
            'status' => ContractorBidStatus::Proposee,
        ]);
    }

    /**
     * Meilleures offres, par score décroissant (voir score()), puis prix croissant et ordre d’arrivée. L’offre choisie
     * y reste, même dépassée depuis par de nouvelles offres.
     *
     * @return Collection<int, ContractorBid>
     */
    public function bestBids(Offer $offer): Collection
    {
        $bids = $this->scored($offer);
        $best = $bids->take(self::BEST_BIDS);
        $chosen = $bids->firstWhere('status', ContractorBidStatus::Choisie);

        return $chosen && ! $best->contains('id', $chosen->id) ? $best->take(self::BEST_BIDS - 1)->push($chosen) : $best;
    }

    /**
     * Pondérations du classement, en points sur 100 (réglage contractors.ranking_weights, sinon WEIGHTS).
     *
     * @return array{note: int, montant: int, duree: int, distance: int}
     */
    public function weights(): array
    {
        $saved = (array) (Setting::query()->whereKey('contractors.ranking_weights')->value('value') ?? []);
        $weights = array_map('intval', array_intersect_key($saved, self::WEIGHTS) + self::WEIGHTS);

        return array_sum($weights) === 100 ? $weights : self::WEIGHTS;
    }

    /**
     * Offres en lice d’entrepreneurs actifs, avec leur score (0 à 100), de la meilleure à la moins bonne.
     *
     * Chaque critère vaut de 0 à 1 et compte selon sa pondération : la note (1 à 5 étoiles ; sans avis, valeur
     * neutre), puis le montant, la durée et la distance comparés aux autres offres (la plus basse vaut 1, la plus
     * haute 0 ; tous égaux, 1 ; distance inconnue, valeur neutre).
     *
     * @return Collection<int, ContractorBid>
     */
    private function scored(Offer $offer): Collection
    {
        $bids = $offer->contractorBids()
            ->with(['contractor.logoMedia'])
            ->whereIn('status', array_map(fn (ContractorBidStatus $status) => $status->value, self::OPEN))
            ->whereHas('contractor', fn (Builder $query) => $query->where('status', ContractorStatus::Actif->value))
            ->get();
        $weights = $this->weights();
        $range = fn (string $key): array => [$bids->pluck($key)->filter(fn ($value) => $value !== null)->min(), $bids->pluck($key)->filter(fn ($value) => $value !== null)->max()];
        $lowerIsBetter = function (?float $value, array $range): float {
            [$min, $max] = $range;

            return match (true) {
                $value === null => self::NEUTRAL,
                $max == $min => 1.0,
                default => ($max - $value) / ($max - $min),
            };
        };
        $ranges = ['amount' => $range('amount'), 'duration_days' => $range('duration_days'), 'distance_km' => $range('distance_km')];

        return $bids
            ->each(function (ContractorBid $bid) use ($weights, $ranges, $lowerIsBetter): void {
                $rating = $bid->contractor->rating_avg !== null ? ((float) $bid->contractor->rating_avg - 1) / 4 : self::NEUTRAL;
                $bid->setAttribute('score', round(
                    $weights['note'] * $rating
                    + $weights['montant'] * $lowerIsBetter((float) $bid->amount, $ranges['amount'])
                    + $weights['duree'] * $lowerIsBetter((float) $bid->duration_days, $ranges['duration_days'])
                    + $weights['distance'] * $lowerIsBetter($bid->distance_km, $ranges['distance_km']),
                    1,
                ));
            })
            ->sort(fn (ContractorBid $a, ContractorBid $b) => [$b->score, $a->amount, $a->created_at] <=> [$a->score, $b->amount, $b->created_at])
            ->values();
    }

    /**
     * « Choisir cette offre » : une seule offre retenue, le chantier attend l’acompte. Le choix reste modifiable tant
     * que l’acompte n’est pas payé.
     */
    public function choose(Site $site, ContractorBid $bid, User $author): ContractorBid
    {
        $offer = $this->openOffer($site);

        if (! $offer || $bid->offer_id !== $offer->getKey()) {
            throw ValidationException::withMessages(['contractor_bid' => 'Offre d’entrepreneur introuvable.']);
        }

        if (! $this->bestBids($offer)->contains('id', $bid->getKey())) {
            throw ValidationException::withMessages(['contractor_bid' => 'Cette offre n’est plus proposée.']);
        }

        return DB::transaction(function () use ($site, $offer, $bid, $author): ContractorBid {
            $offer->contractorBids()->where('status', ContractorBidStatus::Choisie->value)->whereKeyNot($bid->getKey())->update(['status' => ContractorBidStatus::Proposee->value, 'chosen_at' => null, 'chosen_by_user_id' => null]);
            $bid->update(['status' => ContractorBidStatus::Choisie, 'chosen_at' => now(), 'chosen_by_user_id' => $author->getKey()]);

            if ($site->status !== SiteStatus::Paiement) {
                $this->moveSite($site, SiteStatus::Paiement, $author, 'Offre d’entrepreneur choisie');
            }

            return $bid->refresh();
        });
    }

    /**
     * Prix promoteur d’une offre : montant de l’entrepreneur, commission Y, total, acompte X et solde.
     *
     * @return array{workers: int, commission: int, total: int, deposit: int, balance: int}
     */
    public function prices(ContractorBid $bid): array
    {
        return $this->pricing->breakdown($bid->amount);
    }

    /**
     * Offre choisie dont l’acompte reste à payer.
     */
    public function chosenBid(Site $site): ?ContractorBid
    {
        $offer = $this->openOffer($site);

        return $offer?->contractorBids()->where('status', ContractorBidStatus::Choisie->value)->with('contractor')->first();
    }

    /**
     * « Offres d’entrepreneurs » du détail chantier.
     *
     * @return array<string, mixed>
     */
    public function summary(Offer $offer): array
    {
        $bids = $this->bestBids($offer);
        $chosen = $bids->firstWhere('status', ContractorBidStatus::Choisie);

        return [
            'offer_id' => $offer->id,
            'offers' => $bids->values()->map(fn (ContractorBid $bid, int $rank) => [...$this->bid($bid), 'is_best' => $rank === 0])->all(),
            'chosen_id' => $chosen?->id,
            'deposit_percent' => $this->pricing->value(PricingVariable::X),
            'deposit_amount' => $chosen ? $this->prices($chosen)['deposit'] : null,
            'can_pay' => $chosen !== null,
        ];
    }

    /**
     * Action de la carte du chantier.
     *
     * @return array{value: string, label: string}|null
     */
    public function action(Site $site): ?array
    {
        $offer = $this->openOffer($site);

        return match (true) {
            ! $offer => null,
            $offer->contractorBids()->where('status', ContractorBidStatus::Choisie->value)->exists() => ['value' => 'payer_acompte', 'label' => 'Payer l’acompte'],
            $offer->contractorBids()->where('status', ContractorBidStatus::Proposee->value)->exists() => ['value' => 'choisir_entrepreneur', 'label' => 'Choisir une offre'],
            default => null,
        };
    }

    /**
     * Ce que règle l’acompte de l’offre choisie.
     *
     * @return array<string, mixed>
     */
    public function payable(User $user, string $bidId): array
    {
        $bid = ContractorBid::query()
            ->whereKey($bidId)
            ->whereHas('offer.site', fn (Builder $query) => app(PromoterAccess::class)->scopeSites($query, $user))
            ->with(['offer.site', 'contractor'])
            ->first() ?? throw ValidationException::withMessages(['contractor_bid_id' => 'Offre d’entrepreneur introuvable.']);

        if ($bid->status !== ContractorBidStatus::Choisie || $bid->offer->status !== OfferStatus::Publiee) {
            throw ValidationException::withMessages(['contractor_bid_id' => $bid->status === ContractorBidStatus::Retenue
                ? 'L’acompte de cette offre est déjà payé.'
                : 'Choisissez d’abord cette offre.']);
        }

        $prices = $this->prices($bid);
        $x = $this->percent($this->pricing->value(PricingVariable::X));

        return [
            'kind' => 'acompte',
            'label' => 'Acompte '.$x.' · '.$bid->offer->site->label,
            'amount' => $prices['deposit'],
            'site' => $bid->offer->site,
            'proposal' => null,
            'contractor_bid' => $bid,
            'schedules' => collect(),
            'lines' => [
                ['label' => 'Offre de '.$bid->contractor->name.' ('.$bid->duration_days.' jours)', 'amount' => $prices['workers']],
                ['label' => 'Commission O’CHANTIER '.$this->percent($this->pricing->value(PricingVariable::Y)), 'amount' => $prices['commission']],
                ['label' => 'Total du forfait', 'amount' => $prices['total']],
                ['label' => 'Acompte '.$x, 'amount' => $prices['deposit']],
            ],
            'done_text' => 'Acompte payé. '.$bid->contractor->name.' est confirmé sur votre chantier.',
        ];
    }

    /**
     * Acompte payé : l’offre est retenue, les autres ne le sont pas ; le forfait devient un besoin et une mission
     * avec l’instantané tarifaire et l’échéancier. Renvoie l’échéance d’acompte réglée par le paiement.
     *
     * @return Collection<int, PaymentSchedule>
     */
    public function confirm(ContractorBid $bid, User $author): Collection
    {
        $bid = ContractorBid::query()->lockForUpdate()->with(['offer.site', 'contractor'])->findOrFail($bid->getKey());

        if ($bid->status !== ContractorBidStatus::Choisie) {
            throw ValidationException::withMessages(['contractor_bid_id' => 'Cette offre ne peut plus être payée.']);
        }

        $offer = $bid->offer;
        $site = $offer->site;
        $start = CarbonImmutable::parse($offer->start_date)->max(CarbonImmutable::today());
        $end = $start->addDays($bid->duration_days - 1);
        $prices = $this->prices($bid);

        $need = $offer->needs()->create([
            'reference' => sprintf('BS-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('need_reference_seq')")),
            'site_id' => $site->getKey(),
            'offer_type' => OfferType::Contractuelle,
            'trade_id' => Trade::query()->where('code', self::TRADE)->value('id'),
            'role_label' => $bid->contractor->name,
            'is_team_lead' => true,
            'headcount' => $bid->team_size,
            'title' => 'Forfait · '.$bid->contractor->name,
            'description' => 'Travaux de « '.$site->label.' » confiés à '.$bid->contractor->name.' au forfait.',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'duration_days' => $bid->duration_days,
            'payment_frequency' => $offer->payment_mode === ContractPaymentMode::ParEtape ? PaymentFrequency::Jalon : PaymentFrequency::Hebdomadaire,
            'salary_amount' => $bid->amount,
            'salary_period' => SalaryPeriod::Forfait,
            'status' => NeedStatus::Confirme,
            'submitted_at' => now(),
            'created_by_user_id' => $author->getKey(),
        ]);
        $need->statusHistories()->create(['from_status' => null, 'to_status' => NeedStatus::Confirme->value, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);

        $snapshot = $this->snapshot($need, $bid, $prices, $author);
        $mission = $need->missions()->create([
            'reference' => sprintf('MI-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('mission_reference_seq')")),
            'site_id' => $site->getKey(),
            'pricing_snapshot_id' => $snapshot->getKey(),
            'status' => MissionStatus::Confirmee,
            'planned_start_date' => $start->toDateString(),
            'planned_end_date' => $end->toDateString(),
            'planned_days' => $bid->duration_days,
            'deposit_paid_at' => now(),
            'confirmed_at' => now(),
        ]);
        $mission->statusHistories()->create(['from_status' => null, 'to_status' => MissionStatus::Confirmee->value, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
        $deposit = $this->schedule($mission, $offer, $snapshot);

        $offer->contractorBids()->whereKeyNot($bid->getKey())->whereIn('status', [ContractorBidStatus::Proposee->value, ContractorBidStatus::Choisie->value])->update(['status' => ContractorBidStatus::NonRetenue->value]);
        $bid->update(['status' => ContractorBidStatus::Retenue]);
        $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => OfferStatus::Pourvue->value, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
        $offer->forceFill(['status' => OfferStatus::Pourvue, 'contractor_bid_id' => $bid->getKey()])->save();
        $this->moveSite($site, SiteStatus::Confirme, $author, 'Acompte payé');

        return collect([$deposit]);
    }

    /**
     * Échéancier : acompte (réglé par le paiement en cours), puis le solde, chaque semaine de travaux ou en
     * DEFAULT_STEPS étapes de même montant. Une part de l’acompte est déduite de chaque échéance.
     */
    private function schedule(Mission $mission, Offer $offer, PricingSnapshot $snapshot): PaymentSchedule
    {
        $deposit = $mission->paymentSchedules()->create([
            'reference' => $this->reference(),
            'schedule_type' => PaymentScheduleType::Acompte,
            'sequence' => 1,
            'amount_due' => $snapshot->deposit_amount,
            'due_date' => now()->toDateString(),
            'status' => PaymentScheduleStatus::Exigible,
            'pricing_snapshot_id' => $snapshot->getKey(),
            'calculation_details' => ['x_rate' => (float) $snapshot->x_rate, 'total' => $snapshot->total_promoter_amount],
        ]);

        $start = CarbonImmutable::parse($mission->planned_start_date);
        $end = CarbonImmutable::parse($mission->planned_end_date);
        $days = $snapshot->planned_days;
        $byStep = $offer->payment_mode === ContractPaymentMode::ParEtape;
        $periods = [];

        if ($byStep) {
            $count = $this->drafts->installments(ContractPaymentMode::ParEtape, $days)['count'];
            $offer->milestones()->delete();

            for ($k = 1; $k <= $count; $k++) {
                $due = $start->addDays((int) round($days * $k / $count) - 1);
                $periods[] = ['type' => PaymentScheduleType::Jalon, 'start' => null, 'end' => null, 'due' => $due, 'weight' => 1, 'milestone' => 'Étape '.$k.'/'.$count];
            }
        } else {
            $weeks = (int) ceil($days / 7);

            for ($k = 1; $k <= $weeks; $k++) {
                $periodStart = $start->addDays(7 * ($k - 1));
                $periodEnd = $k === $weeks ? $end : $periodStart->addDays(6);
                $periods[] = ['type' => PaymentScheduleType::Hebdomadaire, 'start' => $periodStart, 'end' => $periodEnd, 'due' => $periodEnd, 'weight' => (int) $periodStart->diffInDays($periodEnd) + 1, 'milestone' => null];
            }
        }

        $weights = array_sum(array_column($periods, 'weight'));
        $planned = ['workers' => 0, 'commission' => 0, 'deduction' => 0];

        foreach ($periods as $index => $period) {
            $last = $index === array_key_last($periods);
            $share = fn (int $total, string $key): int => $last ? $total - $planned[$key] : (int) round($total * $period['weight'] / $weights);
            $workers = $share($snapshot->workers_base_amount, 'workers');
            $commission = $share($snapshot->promoter_commission_amount, 'commission');
            $deduction = $share($snapshot->deposit_amount, 'deduction');
            $planned = ['workers' => $planned['workers'] + $workers, 'commission' => $planned['commission'] + $commission, 'deduction' => $planned['deduction'] + $deduction];

            $milestone = $period['milestone']
                ? $offer->milestones()->create(['sequence' => $index + 1, 'label' => $period['milestone'], 'amount' => $workers + $commission, 'due_date' => $period['due']->toDateString()])
                : null;

            $mission->paymentSchedules()->create([
                'reference' => $this->reference(),
                'schedule_type' => $period['type'],
                'sequence' => $index + 1,
                'period_start' => $period['start']?->toDateString(),
                'period_end' => $period['end']?->toDateString(),
                'milestone_id' => $milestone?->getKey(),
                'workers_amount' => $workers,
                'promoter_commission_amount' => $commission,
                'deposit_deduction_amount' => $deduction,
                'amount_due' => $workers + $commission - $deduction,
                'due_date' => $period['due']->toDateString(),
                'status' => PaymentScheduleStatus::Prevue,
                'pricing_snapshot_id' => $snapshot->getKey(),
                'calculation_details' => ['periods' => count($periods), 'weight' => $period['weight']],
            ]);
        }

        return $deposit;
    }

    /**
     * @param  array{workers: int, commission: int, total: int, deposit: int, balance: int}  $prices
     */
    private function snapshot(Need $need, ContractorBid $bid, array $prices, User $author): PricingSnapshot
    {
        return $need->pricingSnapshots()->create([
            'kind' => PricingSnapshotKind::Confirme,
            'offer_type' => OfferType::Contractuelle,
            'price_origin' => $bid->offer->price_origin,
            'x_rate' => $this->pricing->value(PricingVariable::X),
            'y_rate' => $this->pricing->value(PricingVariable::Y),
            'z_rate' => 0,
            'r_rate' => $this->pricing->value(PricingVariable::R),
            'parameter_ids' => PricingParameter::query()->whereIn('code', ['X', 'Y', 'R'])->where('status', 'actif')->where('effective_from', '<=', now())->pluck('id')->all(),
            'headcount' => $bid->team_size,
            'planned_days' => $bid->duration_days,
            'workers_base_amount' => $prices['workers'],
            'promoter_commission_amount' => $prices['commission'],
            'worker_commission_amount' => 0,
            'total_promoter_amount' => $prices['total'],
            'deposit_amount' => $prices['deposit'],
            'balance_amount' => $prices['balance'],
            'lines' => [[
                'contractor_id' => $bid->contractor_id,
                'contractor_bid_id' => $bid->id,
                'name' => $bid->contractor->name,
                'amount' => $bid->amount,
                'days' => $bid->duration_days,
                'team_size' => $bid->team_size,
            ]],
            'computed_by_type' => $author->getMorphClass(),
            'computed_by_id' => $author->getKey(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bid(ContractorBid $bid): array
    {
        $contractor = $bid->contractor;
        $prices = $this->prices($bid);

        return [
            'id' => $bid->id,
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'kind' => ['value' => $contractor->kind->value, 'label' => $contractor->kind->label()],
                'logo_url' => $contractor->logoMedia ? $this->media->temporaryUrl($contractor->logoMedia) : null,
                'rating' => ['average' => $contractor->rating_avg !== null ? (float) $contractor->rating_avg : null, 'count' => $contractor->rating_count],
                'completed_sites' => $contractor->completed_sites_count,
            ],
            'team_size' => $bid->team_size,
            'amount' => $prices['total'],
            'duration_days' => $bid->duration_days,
            'distance_km' => $bid->distance_km,
            'score' => $bid->score,
            'message' => $bid->message,
            'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
            'is_chosen' => $bid->status === ContractorBidStatus::Choisie,
        ];
    }

    private function moveSite(Site $site, SiteStatus $to, Model $actor, string $reason): void
    {
        $from = $site->status;
        $site->forceFill(['status' => $to, 'lock_version' => $site->lock_version + 1])->save();
        $site->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->getKey()]);
    }

    private function percent(float $value): string
    {
        return str_replace('.', ',', (string) (float) $value).' %';
    }

    private function reference(): string
    {
        return sprintf('ECH-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_schedule_reference_seq')"));
    }
}
