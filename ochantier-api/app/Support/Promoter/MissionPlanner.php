<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\PricingSnapshotKind;
use App\Enums\PricingVariable;
use App\Enums\SelectionStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkerReservationStatus;
use App\Models\Mission;
use App\Models\Need;
use App\Models\PaymentSchedule;
use App\Models\PricingParameter;
use App\Models\PricingSnapshot;
use App\Models\Proposal;
use App\Models\Suggestion;
use App\Models\User;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confirmation d’une proposition acceptée, au paiement de l’acompte : pour chaque besoin, prix figé
 * (pricing_snapshots), mission, affectation des ouvriers et échéancier (acompte, puis une échéance par semaine dont
 * est déduite une part de l’acompte).
 */
class MissionPlanner
{
    public function __construct(private PricingRules $pricing) {}

    /**
     * Crée les missions de la proposition et renvoie les échéances d’acompte, à régler par le paiement.
     *
     * @return Collection<int, PaymentSchedule>
     */
    public function confirm(Proposal $proposal, User $author): Collection
    {
        $deposits = collect();

        foreach ($proposal->suggestions()->with(['need.trade', 'worker'])->get()->groupBy('need_id') as $suggestions) {
            /** @var Need $need */
            $need = $suggestions->first()->need;
            $snapshot = $this->snapshot($need, $suggestions, $author);
            $mission = $this->mission($need, $snapshot, $author);
            $this->assign($mission, $need, $suggestions, $snapshot);
            $deposits->push($this->schedule($mission, $snapshot));

            $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::Confirme->value, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
            $need->forceFill(['status' => NeedStatus::Confirme])->save();
        }

        $this->closeOffer($proposal, $author);

        return $deposits;
    }

    /**
     * Prix figé du besoin : coût des ouvriers proposés sur la durée, commissions et acompte.
     *
     * @param  Collection<int, Suggestion>  $suggestions
     */
    private function snapshot(Need $need, Collection $suggestions, User $author): PricingSnapshot
    {
        $days = $need->duration_days;
        $prices = $this->pricing->breakdown((int) $suggestions->sum('promoter_daily_rate') * $days);
        $parameters = PricingParameter::query()->whereIn('code', ['X', 'Y', 'Z', 'R'])->where('status', 'actif')->where('effective_from', '<=', now())->pluck('id');

        return $need->pricingSnapshots()->create([
            'kind' => PricingSnapshotKind::Confirme,
            'offer_type' => $need->offer_type,
            'price_origin' => $need->offer->price_origin,
            'x_rate' => $this->pricing->value(PricingVariable::X),
            'y_rate' => $this->pricing->value(PricingVariable::Y),
            'z_rate' => $this->pricing->value(PricingVariable::Z),
            'r_rate' => $this->pricing->value(PricingVariable::R),
            'parameter_ids' => $parameters->all(),
            'headcount' => $suggestions->count(),
            'planned_days' => $days,
            'workers_base_amount' => $prices['workers'],
            'promoter_commission_amount' => $prices['commission'],
            'worker_commission_amount' => (int) $suggestions->sum(fn (Suggestion $suggestion) => ($suggestion->promoter_daily_rate - $suggestion->net_daily_rate) * $days),
            'total_promoter_amount' => $prices['total'],
            'deposit_amount' => $prices['deposit'],
            'balance_amount' => $prices['balance'],
            'lines' => $suggestions->map(fn (Suggestion $suggestion) => [
                'worker_id' => $suggestion->worker_id,
                'name' => trim($suggestion->worker->first_name.' '.$suggestion->worker->last_name),
                'promoter_daily_rate' => $suggestion->promoter_daily_rate,
                'net_daily_rate' => $suggestion->net_daily_rate,
                'days' => $days,
                'gross_amount' => $suggestion->promoter_daily_rate * $days,
                'worker_commission_amount' => ($suggestion->promoter_daily_rate - $suggestion->net_daily_rate) * $days,
            ])->values()->all(),
            'computed_by_type' => $author->getMorphClass(),
            'computed_by_id' => $author->getKey(),
        ]);
    }

    private function mission(Need $need, PricingSnapshot $snapshot, User $author): Mission
    {
        $mission = $need->missions()->create([
            'reference' => sprintf('MI-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('mission_reference_seq')")),
            'site_id' => $need->site_id,
            'pricing_snapshot_id' => $snapshot->getKey(),
            'status' => MissionStatus::Confirmee,
            'planned_start_date' => $need->start_date,
            'planned_end_date' => $need->end_date ?? $need->start_date,
            'planned_days' => $need->duration_days,
            'deposit_paid_at' => now(),
            'confirmed_at' => now(),
        ]);
        $mission->statusHistories()->create(['from_status' => null, 'to_status' => MissionStatus::Confirmee->value, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);

        return $mission;
    }

    /**
     * Affectation des ouvriers proposés : conditions financières figées, sélection confirmée, réservation convertie.
     *
     * @param  Collection<int, Suggestion>  $suggestions
     */
    private function assign(Mission $mission, Need $need, Collection $suggestions, PricingSnapshot $snapshot): void
    {
        foreach ($suggestions as $suggestion) {
            $selection = $need->selections()->where('suggestion_id', $suggestion->getKey())->firstOrFail();

            $mission->assignments()->create([
                'need_id' => $need->getKey(),
                'worker_id' => $suggestion->worker_id,
                'selection_id' => $selection->getKey(),
                'commercial_id' => $suggestion->worker->current_commercial_id,
                'role_label' => $need->trade->label,
                'planned_start_date' => $mission->planned_start_date,
                'planned_end_date' => $mission->planned_end_date,
                'promoter_daily_rate' => $suggestion->promoter_daily_rate,
                'worker_net_daily_rate' => $suggestion->net_daily_rate,
                'z_rate' => $snapshot->z_rate,
                'status' => AssignmentStatus::Confirmee,
            ]);

            $selection->forceFill(['status' => SelectionStatus::Confirme])->save();
            $need->workerReservations()->where('selection_id', $selection->getKey())->update(['status' => WorkerReservationStatus::Convertie, 'released_at' => now()]);
        }
    }

    /**
     * Échéancier de la mission : acompte (réglé par le paiement en cours), puis une échéance par semaine travaillée,
     * dont est déduite une part de l’acompte proportionnelle au coût de la semaine.
     */
    private function schedule(Mission $mission, PricingSnapshot $snapshot): PaymentSchedule
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
        $daily = intdiv($snapshot->workers_base_amount, max(1, $snapshot->planned_days));
        $weeks = (int) ceil($snapshot->planned_days / 7);
        $deducted = 0;
        $workersPlanned = 0;
        $commissionPlanned = 0;

        for ($k = 1; $k <= $weeks; $k++) {
            $periodStart = $start->addDays(7 * ($k - 1));
            $periodEnd = $k === $weeks ? $end : $periodStart->addDays(6);
            $days = (int) $periodStart->diffInDays($periodEnd) + 1;
            $last = $k === $weeks;
            $workers = $last ? $snapshot->workers_base_amount - $workersPlanned : $daily * $days;
            $commission = $last ? $snapshot->promoter_commission_amount - $commissionPlanned : $this->pricing->commission($workers);
            $deduction = $last ? $snapshot->deposit_amount - $deducted : (int) round($snapshot->deposit_amount * $workers / $snapshot->workers_base_amount);
            $workersPlanned += $workers;
            $commissionPlanned += $commission;
            $deducted += $deduction;

            $mission->paymentSchedules()->create([
                'reference' => $this->reference(),
                'schedule_type' => PaymentScheduleType::Hebdomadaire,
                'sequence' => $k,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'workers_amount' => $workers,
                'promoter_commission_amount' => $commission,
                'deposit_deduction_amount' => $deduction,
                'amount_due' => $workers + $commission - $deduction,
                'due_date' => $periodEnd->toDateString(),
                'status' => PaymentScheduleStatus::Prevue,
                'pricing_snapshot_id' => $snapshot->getKey(),
                'calculation_details' => ['days' => $days, 'daily_workers_amount' => $daily, 'weeks' => $weeks],
            ]);
        }

        return $deposit;
    }

    /**
     * Le chantier passe en « Équipe confirmée » ; l’offre est pourvue quand plus aucun besoin n’est recherché.
     */
    private function closeOffer(Proposal $proposal, User $author): void
    {
        $offer = $proposal->offer;
        $searching = $offer->needs()->whereNotIn('status', [NeedStatus::Confirme, NeedStatus::EnCours, NeedStatus::Termine, NeedStatus::Annule])->exists();

        if (! $searching && $offer->status === OfferStatus::Publiee) {
            $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => OfferStatus::Pourvue->value, 'reason' => 'Équipe confirmée', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
            $offer->forceFill(['status' => OfferStatus::Pourvue])->save();
        }

        $site = $proposal->site;

        if (in_array($site->status, [SiteStatus::Soumis, SiteStatus::Recherche, SiteStatus::Paiement], true)) {
            $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::Confirme, 'reason' => 'Acompte payé', 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
            $site->forceFill(['status' => SiteStatus::Confirme])->save();
        }
    }

    private function reference(): string
    {
        return sprintf('ECH-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_schedule_reference_seq')"));
    }
}
