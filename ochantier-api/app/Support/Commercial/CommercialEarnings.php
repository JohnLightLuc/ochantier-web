<?php

namespace App\Support\Commercial;

use App\Enums\CommercialAdjustmentKind;
use App\Enums\CommercialAdjustmentStatus;
use App\Enums\CommercialEarningEvent;
use App\Enums\CommercialEarningStatus;
use App\Enums\CommercialPayoutStatus;
use App\Enums\EarningCalculation;
use App\Enums\ParameterStatus;
use App\Models\CommercialAdjustment;
use App\Models\CommercialEarning;
use App\Models\CommercialEarningRule;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * « Gains » du commercial : une commission par ouvrier validé (règle en vigueur, montant fixe). Estimée dès la
 * soumission du dossier, confirmée (acquise) à sa validation par le Back office, versée en fin de mois par Mobile
 * Money, annulée si le dossier est refusé.
 */
class CommercialEarnings
{
    /**
     * Commissions confirmées (acquises, approuvées ou versées).
     */
    public const CONFIRMED = [CommercialEarningStatus::Acquis, CommercialEarningStatus::Approuve, CommercialEarningStatus::Paye];

    /**
     * Commissions estimées, en attente de la validation du dossier.
     */
    public const PENDING = [CommercialEarningStatus::Estime, CommercialEarningStatus::EnAttente];

    /**
     * Onglets de l’historique : clé => [libellé, statuts].
     *
     * @var array<string, array{0: string, 1: list<CommercialEarningStatus>}>
     */
    public const TABS = [
        'toutes' => ['Toutes', [...self::CONFIRMED, ...self::PENDING]],
        'confirmees' => ['Confirmées', self::CONFIRMED],
        'en_attente' => ['En attente', self::PENDING],
    ];

    /**
     * Commission estimée à la soumission du dossier (une seule par ouvrier).
     */
    public function estimate(Worker $worker): ?CommercialEarning
    {
        $rule = $this->rule();

        // Un ouvrier enregistré par le Back Office n’ouvre pas de commission à son commercial référent.
        if (! $rule || ! $worker->origin_commercial_id || $worker->acquisition_channel === Worker::BACK_OFFICE_CHANNEL) {
            return null;
        }

        return CommercialEarning::query()->firstOrCreate(
            ['worker_id' => $worker->getKey(), 'event_type' => CommercialEarningEvent::OuvrierValide],
            [
                'commercial_id' => $worker->origin_commercial_id,
                'rule_id' => $rule->getKey(),
                'event_at' => now(),
                'week_start' => CarbonImmutable::today()->startOfWeek()->toDateString(),
                'amount' => $this->amount($rule),
                'status' => CommercialEarningStatus::Estime,
                'pending_reason' => 'Dossier en traitement par le Back office',
            ],
        );
    }

    /**
     * Commission confirmée à la validation de l’ouvrier.
     */
    public function confirm(Worker $worker, ?string $adminId = null): ?CommercialEarning
    {
        $earning = $this->estimate($worker);

        $earning?->update([
            'status' => CommercialEarningStatus::Acquis,
            'event_at' => now(),
            'week_start' => CarbonImmutable::today()->startOfWeek()->toDateString(),
            'pending_reason' => null,
            'approved_by_admin_id' => $adminId,
            'approved_at' => now(),
        ]);

        return $earning;
    }

    /**
     * Dossier refusé : la commission estimée est annulée.
     */
    public function cancel(Worker $worker): void
    {
        CommercialEarning::query()
            ->where('worker_id', $worker->getKey())
            ->whereIn('status', self::PENDING)
            ->update(['status' => CommercialEarningStatus::Annule, 'pending_reason' => 'Dossier refusé par le Back office']);
    }

    /**
     * Ce qui reste dû jusqu’à la fin du mois : commissions confirmées, primes et retenues approuvées, pas encore
     * rattachées à un versement. Une retenue trop forte se reporte donc sur le versement suivant.
     *
     * @return array{earnings: Collection<int, CommercialEarning>, adjustments: Collection<int, CommercialAdjustment>, commissions: int, bonuses: int, deductions: int, total: int}
     */
    public function due(CommercialProfile $commercial, CarbonImmutable $month, bool $lock = false): array
    {
        $end = $month->endOfMonth();
        $earnings = $commercial->commercialEarnings()
            ->whereIn('status', [CommercialEarningStatus::Acquis, CommercialEarningStatus::Approuve])
            ->whereNull('commercial_payout_id')
            ->where('event_at', '<=', $end)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
        $adjustments = $commercial->commercialAdjustments()
            ->where('status', CommercialAdjustmentStatus::Approuvee)
            ->whereNull('commercial_payout_id')
            ->where('granted_on', '<=', $end->toDateString())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
        $commissions = (int) $earnings->sum('amount');
        $bonuses = (int) $adjustments->where('kind', CommercialAdjustmentKind::Prime)->sum('amount');
        $deductions = (int) $adjustments->where('kind', CommercialAdjustmentKind::Retenue)->sum('amount');

        return ['earnings' => $earnings, 'adjustments' => $adjustments, 'commissions' => $commissions, 'bonuses' => $bonuses, 'deductions' => $deductions, 'total' => $commissions + $bonuses - $deductions];
    }

    /**
     * Crée le versement du mois et lui rattache ce qui est dû. Renvoie null s’il n’y a rien à verser.
     */
    public function attach(CommercialProfile $commercial, CarbonImmutable $month, CommercialPayoutStatus $status, ?string $adminId = null): ?CommercialPayout
    {
        $due = $this->due($commercial, $month, true);

        if ($due['total'] <= 0) {
            return null;
        }

        $payout = $commercial->commercialPayouts()->create([
            'reference' => sprintf('VRS-%s-%s', $month->format('Ym'), mb_strtoupper(substr(str_replace('-', '', (string) $commercial->employee_number), -5))).'-'.random_int(100, 999),
            'period_start' => $month->startOfMonth()->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'total_amount' => $due['total'],
            'status' => $status,
            'created_by_admin_id' => $adminId,
        ]);

        CommercialEarning::query()->whereKey($due['earnings']->modelKeys())->update(['commercial_payout_id' => $payout->getKey()]);
        CommercialAdjustment::query()->whereKey($due['adjustments']->modelKeys())->update(['commercial_payout_id' => $payout->getKey()]);

        return $payout;
    }

    /**
     * Versement réussi : commissions et ajustements rattachés passent à « payé ».
     */
    public function settle(CommercialPayout $payout): void
    {
        $payout->forceFill(['status' => CommercialPayoutStatus::Paye, 'paid_at' => now()])->save();
        $payout->commercialEarnings()->update(['status' => CommercialEarningStatus::Paye]);
        $payout->commercialAdjustments()->update(['status' => CommercialAdjustmentStatus::Payee]);
    }

    /**
     * Versement simulé hors production, sans passer par le Back Office.
     */
    public function pay(CommercialProfile $commercial, CarbonImmutable $month, ?string $adminId = null): ?CommercialPayout
    {
        return DB::transaction(function () use ($commercial, $month, $adminId): ?CommercialPayout {
            $payout = $this->attach($commercial, $month, CommercialPayoutStatus::EnTraitement, $adminId);

            if ($payout) {
                $this->settle($payout);
            }

            return $payout;
        });
    }

    /**
     * Écran « Gains » du mois : commissions confirmées dont versées, estimées en attente, historique filtré.
     *
     * @return array<string, mixed>
     */
    public function month(CommercialProfile $commercial, CarbonImmutable $month, string $tab = 'toutes'): array
    {
        $earnings = $commercial->commercialEarnings()
            ->whereIn('status', [...self::CONFIRMED, ...self::PENDING])
            ->whereBetween('event_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->with(['worker.workerTrades.trade', 'commercialPayout'])
            ->latest('event_at')
            ->get();
        $confirmed = (int) $earnings->whereIn('status', self::CONFIRMED)->sum('amount');
        $paid = (int) $earnings->where('status', CommercialEarningStatus::Paye)->sum('amount');
        $pending = (int) $earnings->whereIn('status', self::PENDING)->sum('amount');

        return [
            'month' => $month->format('Y-m'),
            'month_label' => mb_convert_case($month->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE),
            'confirmed' => $confirmed,
            'confirmed_label' => $this->money($confirmed),
            'paid' => $paid,
            'paid_label' => $this->money($paid),
            'paid_percent' => $confirmed ? (int) round($paid / $confirmed * 100) : 0,
            'pending' => $pending,
            'pending_label' => $this->money($pending),
            'tabs' => collect(self::TABS)->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition[0],
                'count' => $earnings->whereIn('status', $definition[1])->count(),
            ])->values()->all(),
            'history' => $earnings->whereIn('status', self::TABS[$tab][1])->map(fn (CommercialEarning $earning) => $this->row($earning))->values()->all(),
            'adjustments' => $commercial->commercialAdjustments()
                ->whereIn('status', [CommercialAdjustmentStatus::Approuvee, CommercialAdjustmentStatus::Payee])
                ->whereBetween('granted_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->latest('granted_on')->get()
                ->map(fn (CommercialAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'kind' => ['value' => $adjustment->kind->value, 'label' => $adjustment->kind->label()],
                    'amount' => $adjustment->amount,
                    'amount_label' => ($adjustment->kind === CommercialAdjustmentKind::Prime ? '+' : '−').$this->money($adjustment->amount),
                    'reason' => $adjustment->reason,
                    'status' => ['value' => $adjustment->status->value, 'label' => $adjustment->status->label()],
                ])->values()->all(),
            'note' => 'Les montants estimés peuvent évoluer après validation du Back office.',
        ];
    }

    /**
     * Mois proposés dans le sélecteur : du mois en cours au premier mois avec une commission (6 au plus).
     *
     * @return list<array{value: string, label: string}>
     */
    public function months(CommercialProfile $commercial): array
    {
        $first = $commercial->commercialEarnings()->min('event_at');
        $start = $first ? CarbonImmutable::parse($first)->startOfMonth() : CarbonImmutable::today()->startOfMonth();
        $months = [];

        for ($month = CarbonImmutable::today()->startOfMonth(); $month->greaterThanOrEqualTo($start) && count($months) < 6; $month = $month->subMonth()) {
            $months[] = ['value' => $month->format('Y-m'), 'label' => mb_convert_case($month->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE)];
        }

        return $months;
    }

    /**
     * « Gains de la semaine » de l’accueil : confirmées, en attente et versées cette semaine.
     *
     * @return array<string, mixed>
     */
    public function week(CommercialProfile $commercial): array
    {
        $start = CarbonImmutable::today()->startOfWeek();
        $end = $start->endOfWeek();
        $acquired = (int) $commercial->commercialEarnings()->whereIn('status', self::CONFIRMED)->whereBetween('approved_at', [$start, $end])->sum('amount');
        $pending = (int) $commercial->commercialEarnings()->whereIn('status', self::PENDING)->sum('amount');
        $paid = (int) $commercial->commercialPayouts()->where('status', CommercialPayoutStatus::Paye)->whereBetween('paid_at', [$start, $end])->sum('total_amount');

        return [
            'acquired' => $acquired,
            'acquired_label' => $this->short($acquired),
            'pending' => $pending,
            'pending_label' => $this->short($pending),
            'paid' => $paid,
            'paid_label' => $this->short($paid),
        ];
    }

    public function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CommercialEarning $earning): array
    {
        $worker = $earning->worker;
        $name = trim($worker->first_name.' '.$worker->last_name);
        $state = match (true) {
            $earning->status === CommercialEarningStatus::Paye => ['value' => 'versee', 'label' => 'Versée'],
            in_array($earning->status, self::CONFIRMED, true) => ['value' => 'confirmee', 'label' => 'Confirmée'],
            default => ['value' => 'en_attente', 'label' => 'En attente'],
        };
        $date = CarbonImmutable::parse($earning->event_at);

        return [
            'id' => $earning->id,
            'worker_id' => $worker->id,
            'prospect_id' => $worker->prospect_id,
            'name' => $name,
            'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
            'subtitle' => match ($state['value']) {
                'versee' => 'Commission versée · '.$this->date(CarbonImmutable::parse($earning->commercialPayout?->paid_at ?? $date)),
                'confirmee' => 'Dossier validé · '.$this->date($date),
                default => 'Dossier en traitement · '.$this->date($date),
            },
            'amount' => $earning->amount,
            'amount_label' => ($state['value'] === 'en_attente' ? '' : '+').number_format($earning->amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA",
            'status' => $state,
            'event_at' => $date->toIso8601String(),
        ];
    }

    private function rule(): ?CommercialEarningRule
    {
        return CommercialEarningRule::query()
            ->where('event_type', CommercialEarningEvent::OuvrierValide)
            ->where('status', ParameterStatus::Actif)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->latest('effective_from')
            ->first();
    }

    private function amount(CommercialEarningRule $rule): int
    {
        return $rule->calculation === EarningCalculation::MontantFixe ? (int) $rule->amount : 0;
    }

    private function short(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}F";
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M');
    }
}
