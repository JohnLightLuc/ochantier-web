<?php

namespace App\Support\Admin;

use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceCorrectionStatus;
use App\Enums\DisputeStatus;
use App\Enums\MissionStatus;
use App\Enums\OfferType;
use App\Enums\PaymentInstallmentStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\RefundStatus;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Models\Dispute;
use App\Models\Mission;
use App\Models\PaymentInstallment;
use App\Models\PaymentSchedule;
use App\Support\Promoter\PromoterNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Régularisation d’une mission terminée. Les échéances suivent le plan ; à la fin, le coût réel (journées validées,
 * commission figée au prix convenu) est comparé à ce que le promoteur a réglé. Les échéances impayées sont annulées et
 * une échéance de régularisation porte l’écart : le complément à payer, ou zéro avec un remboursement à valider.
 */
class MissionRegularisations
{
    /**
     * Délai de paiement d’un complément (jours).
     */
    private const DUE_DAYS = 7;

    public function __construct(private Refunds $refunds, private PromoterNotifier $notifier) {}

    /**
     * Missions terminées, hors contrats dont le montant reste celui convenu.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Mission>
     */
    public function queue(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $regularised = fn (Builder $query) => $query->where('schedule_type', PaymentScheduleType::Regularisation);

        return Mission::query()
            ->where('status', MissionStatus::Terminee)
            ->whereHas('pricingSnapshot', fn (Builder $query) => $query->where('offer_type', '!=', OfferType::Contractuelle))
            ->when(($filters['state'] ?? null) === 'a_faire', fn (Builder $query) => $query->whereDoesntHave('paymentSchedules', $regularised))
            ->when(($filters['state'] ?? null) === 'faite', fn (Builder $query) => $query->whereHas('paymentSchedules', $regularised))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhereHas('site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->with(['site.promoterProfile.user', 'pricingSnapshot', 'paymentSchedules', 'refunds'])
            ->latest('closed_at')
            ->orderBy('id');
    }

    public function isContract(Mission $mission): bool
    {
        return $mission->pricingSnapshot->offer_type === OfferType::Contractuelle;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Mission $mission): array
    {
        $regularisation = $this->regularisation($mission);
        $profile = $mission->site->promoterProfile;
        $figures = $regularisation ? $regularisation->calculation_details : $this->figures($mission);
        $contract = $this->isContract($mission);

        return [
            'id' => $mission->id,
            'reference' => $mission->reference,
            'site' => ['id' => $mission->site->id, 'label' => $mission->site->label],
            'promoter' => ['id' => $profile->id, 'full_name' => trim($profile->user->first_name.' '.$profile->user->last_name)],
            'closed_at' => $mission->closed_at?->toIso8601String(),
            'state' => $this->state($regularisation, $contract),
            ...$figures,
            'blockers' => $regularisation || $contract ? [] : $this->blockers($mission),
            'schedule' => $regularisation ? ['id' => $regularisation->id, 'reference' => $regularisation->reference, 'amount_due' => $regularisation->amount_due, 'amount_paid' => $regularisation->amount_paid, 'status' => ['value' => $regularisation->status->value, 'label' => $regularisation->status->label()], 'due_date' => $regularisation->due_date?->toDateString()] : null,
        ];
    }

    /**
     * Coût réel de la mission comparé à ce qui a été réglé. Écart positif : le promoteur doit ce complément.
     *
     * @return array{days: int, workers_amount: int, commission_amount: int, y_rate: float, total: int, planned_total: int, paid: int, refunded: int, balance: int}
     */
    public function figures(Mission $mission): array
    {
        $worked = DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->where('d.mission_id', $mission->getKey())
            ->where('d.status', WorkDayStatus::Valide->value)
            ->selectRaw('count(*) as days, coalesce(sum(coalesce(a.promoter_daily_rate, a.worker_net_daily_rate, 0)), 0) as workers')
            ->first();

        $snapshot = $mission->pricingSnapshot;
        $workers = (int) $worked->workers;
        $commission = (int) round($workers * (float) $snapshot->y_rate / 100);
        $paid = (int) $mission->paymentSchedules()->sum('amount_paid');
        $refunded = (int) $mission->refunds()->where('status', RefundStatus::Execute)->sum('amount');

        return [
            'days' => (int) $worked->days,
            'workers_amount' => $workers,
            'commission_amount' => $commission,
            'y_rate' => (float) $snapshot->y_rate,
            'total' => $workers + $commission,
            'planned_total' => $snapshot->total_promoter_amount,
            'paid' => $paid,
            'refunded' => $refunded,
            'balance' => $workers + $commission - $paid + $refunded,
        ];
    }

    /**
     * Ce qui peut encore changer le coût réel ou ce qui a été réglé.
     *
     * @return list<string>
     */
    public function blockers(Mission $mission): array
    {
        $id = $mission->getKey();
        $days = $mission->workDays()->whereIn('status', [WorkDayStatus::EnCours, WorkDayStatus::AValider])->count();
        $onDays = fn (Builder $query) => $query->where('mission_id', $id);

        return array_values(array_filter([
            $days > 0 ? $days.' journée'.($days > 1 ? 's' : '').' à valider' : null,
            AttendanceCorrection::query()->where('status', AttendanceCorrectionStatus::Demandee)->whereHas('workDay', $onDays)->exists() ? 'Correction de pointage en attente' : null,
            AttendanceAnomaly::query()->whereIn('status', [AttendanceAnomalyStatus::Ouverte, AttendanceAnomalyStatus::EnTraitement])->whereHas('workDay', $onDays)->exists() ? 'Anomalie de pointage ouverte' : null,
            Dispute::query()->where('mission_id', $id)->whereIn('status', [DisputeStatus::Ouvert, DisputeStatus::EnInstruction, DisputeStatus::EnRecours])->exists() ? 'Litige ouvert' : null,
            PaymentInstallment::query()->where('site_id', $mission->site_id)->where('status', PaymentInstallmentStatus::APayer)->exists() ? 'Paiement en plusieurs fois en cours' : null,
            $mission->paymentSchedules()->whereIn('status', [PaymentScheduleStatus::PartiellementPayee, PaymentScheduleStatus::Contestee])->exists() ? 'Échéance partiellement payée ou contestée' : null,
        ]));
    }

    public function run(Mission $mission, Admin $admin, Request $request): PaymentSchedule
    {
        $schedule = DB::transaction(function () use ($mission, $admin, $request): PaymentSchedule {
            $mission = Mission::query()->whereKey($mission->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOpen($mission);

            if (($blockers = $this->blockers($mission)) !== []) {
                throw ValidationException::withMessages(['regularisation' => $blockers[0].'.']);
            }

            $figures = $this->figures($mission);
            $owed = max(0, $figures['balance']);

            $mission->paymentSchedules()
                ->where('schedule_type', '!=', PaymentScheduleType::Acompte)
                ->where('amount_paid', 0)
                ->whereNotIn('status', [PaymentScheduleStatus::Payee, PaymentScheduleStatus::Annulee, PaymentScheduleStatus::Remboursee])
                ->update(['status' => PaymentScheduleStatus::Annulee]);

            [$first, $last] = $this->span($mission);

            $schedule = $mission->paymentSchedules()->create([
                'reference' => sprintf('ECH-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_schedule_reference_seq')")),
                'schedule_type' => PaymentScheduleType::Regularisation,
                'sequence' => 1,
                'period_start' => $first,
                'period_end' => $last,
                'adjustment_amount' => $figures['balance'],
                'amount_due' => $owed,
                'due_date' => now()->addDays(self::DUE_DAYS)->toDateString(),
                'status' => $owed > 0 ? PaymentScheduleStatus::Exigible : PaymentScheduleStatus::Payee,
                'pricing_snapshot_id' => $mission->pricing_snapshot_id,
                'calculation_details' => $figures,
            ]);

            if ($figures['balance'] < 0) {
                $this->refunds->fromRegularisation($mission, -$figures['balance'], $admin, $request);
            }

            AdminAudit::record($admin, 'payments', 'payments.mission_regularised', $request, $mission, null, $figures);

            return $schedule;
        });

        if ($schedule->amount_due > 0) {
            $site = $mission->site;
            $this->notifier->regularisationDue($schedule, $site);
        }

        return $schedule;
    }

    private function ensureOpen(Mission $mission): void
    {
        if ($mission->status !== MissionStatus::Terminee) {
            throw ValidationException::withMessages(['regularisation' => 'Seule une mission terminée peut être régularisée.']);
        }

        if ($this->isContract($mission)) {
            throw ValidationException::withMessages(['regularisation' => 'Un contrat reste au montant convenu : il n’est pas régularisé.']);
        }

        if ($mission->paymentSchedules()->where('schedule_type', PaymentScheduleType::Regularisation)->exists()) {
            throw ValidationException::withMessages(['regularisation' => 'Cette mission est déjà régularisée.']);
        }
    }

    /**
     * La période couvre toute la mission, journées hors plan comprises.
     *
     * @return array{0: string, 1: string}
     */
    private function span(Mission $mission): array
    {
        $dates = [$mission->planned_start_date, $mission->planned_end_date, $mission->workDays()->min('work_date'), $mission->workDays()->max('work_date')];
        $dates = collect($dates)->filter()->map(fn ($date) => CarbonImmutable::parse($date)->toDateString());

        return [$dates->min(), $dates->max()];
    }

    private function regularisation(Mission $mission): ?PaymentSchedule
    {
        return $mission->paymentSchedules->firstWhere('schedule_type', PaymentScheduleType::Regularisation);
    }

    /**
     * @return array{value: string, label: string}
     */
    private function state(?PaymentSchedule $regularisation, bool $contract): array
    {
        return match (true) {
            $contract => ['value' => 'contrat', 'label' => 'Montant du contrat'],
            $regularisation !== null => ['value' => 'faite', 'label' => 'Régularisée'],
            default => ['value' => 'a_faire', 'label' => 'À régulariser'],
        };
    }
}
