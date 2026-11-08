<?php

namespace App\Support\Admin;

use App\Enums\PaymentInstallmentStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentOperator;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paiements du Back Office : encaissements des promoteurs (une ligne par transaction) et échéances des missions (une
 * ligne par échéance, avec son état du jour).
 *
 * L’état d’une échéance suit l’app promoteur : si elle est couverte par un paiement flexible, c’est la date du prochain
 * versement à régler qui compte.
 */
class PaymentDirectory
{
    public const TRANSACTION_STATUSES = ['initiee', 'en_attente', 'reussie', 'echouee', 'expiree', 'annulee'];

    public const PURPOSES = ['acompte', 'echeance', 'abonnement', 'commande_devis'];

    public const TRANSACTION_SORTS = ['date' => 'payment_transactions.initiated_at', 'amount' => 'payment_transactions.amount', 'status' => 'payment_transactions.status'];

    public const SCHEDULE_STATES = ['prevue', 'exigible', 'partiellement_payee', 'en_retard', 'payee', 'contestee', 'annulee', 'remboursee'];

    public const SCHEDULE_TYPES = ['acompte', 'hebdomadaire', 'regularisation'];

    public const SCHEDULE_SORTS = ['due' => 'effective_due', 'amount' => 'payment_schedules.amount_due', 'state' => 'state'];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (TransactionIndexRequest).
     * @return Builder<PaymentTransaction>
     */
    public function transactions(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'date';
        $direction = ($filters['direction'] ?? ($sort === 'status' ? 'asc' : 'desc')) === 'desc' ? 'desc' : 'asc';

        return $this->filteredTransactions($filters)
            ->select('payment_transactions.*')
            ->selectRaw('sites.id as site_key, sites.reference as site_reference, sites.label as site_label')
            ->selectRaw($this->promoterName().' as promoter_name')
            ->with('operator')
            ->orderByRaw(self::TRANSACTION_SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('payment_transactions.id');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, collected: int, fees: int, month: int, failed: int}
     */
    public function transactionCounts(): array
    {
        $rows = PaymentTransaction::query()->toBase()->where('direction', PaymentTransactionDirection::Encaissement->value);
        $statuses = (clone $rows)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $success = (clone $rows)->where('status', PaymentTransactionStatus::Reussie->value);

        return [
            'total' => (int) $statuses->sum(),
            'statuses' => collect(self::TRANSACTION_STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) $statuses->get($status, 0)])->all(),
            'collected' => (int) (clone $success)->sum('amount'),
            'fees' => (int) (clone $success)->sum('fees_amount'),
            'month' => (int) (clone $success)->where('completed_at', '>=', now()->startOfMonth())->sum('amount'),
            'failed' => (int) $statuses->get(PaymentTransactionStatus::Echouee->value, 0),
        ];
    }

    /**
     * @return array{operators: list<array{id: string, name: string}>}
     */
    public function transactionOptions(): array
    {
        return ['operators' => PaymentOperator::query()->orderBy('name')->get(['id', 'name'])->map(fn (PaymentOperator $operator) => ['id' => $operator->id, 'name' => $operator->name])->all()];
    }

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (ScheduleIndexRequest).
     * @return Builder<PaymentSchedule>
     */
    public function schedules(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'due';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $this->filteredSchedules($filters)
            ->select('payment_schedules.*')
            ->selectRaw($this->stateSql().' as state')
            ->selectRaw('coalesce(flexible.next_due, payment_schedules.due_date) as effective_due')
            ->selectRaw('flexible.next_due is not null as is_flexible')
            ->with(['mission.site.organization', 'mission.site.promoterProfile.user', 'mission.need.trade'])
            ->orderByRaw(self::SCHEDULE_SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('payment_schedules.reference');
    }

    /**
     * @return array{total: int, states: array<string, int>, late_amount: int, open_amount: int, paid_amount: int}
     */
    public function scheduleCounts(): array
    {
        $states = $this->scheduleBase()->toBase()->selectRaw($this->stateSql().' as state, count(*) as total, sum(payment_schedules.amount_due - payment_schedules.amount_paid) as remaining')->groupBy('state')->get()->keyBy('state');
        $remaining = fn (array $list): int => (int) collect($list)->sum(fn (string $state) => $states->get($state)?->remaining ?? 0);

        return [
            'total' => (int) $states->sum('total'),
            'states' => collect(self::SCHEDULE_STATES)->mapWithKeys(fn (string $state) => [$state => (int) ($states->get($state)?->total ?? 0)])->all(),
            'late_amount' => $remaining(['en_retard']),
            'open_amount' => $remaining(['exigible', 'partiellement_payee', 'en_retard']),
            'paid_amount' => (int) $this->scheduleBase()->sum('payment_schedules.amount_paid'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PaymentTransaction>
     */
    private function filteredTransactions(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return PaymentTransaction::query()
            ->where('payment_transactions.direction', PaymentTransactionDirection::Encaissement->value)
            ->leftJoin('sites', fn ($join) => $join->whereRaw("sites.id::text = payment_transactions.metadata->>'site_id'"))
            ->leftJoin('promoter_profiles', fn ($join) => $join->on('promoter_profiles.id', '=', 'payment_transactions.payer_id')->where('payment_transactions.payer_type', 'promoter_profile'))
            ->leftJoin('users', 'users.id', '=', 'promoter_profiles.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'sites.organization_id')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('payment_transactions.status', $status))
            ->when($filters['purpose'] ?? null, fn (Builder $query, string $purpose) => $query->where('payment_transactions.purpose', $purpose))
            ->when($filters['operator_id'] ?? null, fn (Builder $query, string $operator) => $query->where('payment_transactions.operator_id', $operator))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $site) => $query->where('sites.id', $site))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('payment_transactions.initiated_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('payment_transactions.initiated_at', '<', date('Y-m-d', strtotime($date.' +1 day'))))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('payment_transactions.reference', 'ilike', $search)
                ->orWhere('payment_transactions.provider_reference', 'ilike', $search)
                ->orWhere('sites.label', 'ilike', $search)
                ->orWhere('sites.reference', 'ilike', $search)
                ->orWhereRaw($this->promoterName().' ilike ?', [$search])));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PaymentSchedule>
     */
    private function filteredSchedules(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return $this->scheduleBase()
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->whereRaw($this->stateSql().' = ?', [$state]))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('payment_schedules.schedule_type', $type))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $site) => $query->where('sites.id', $site))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereRaw('coalesce(flexible.next_due, payment_schedules.due_date) >= ?', [$date]))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereRaw('coalesce(flexible.next_due, payment_schedules.due_date) <= ?', [$date]))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('payment_schedules.reference', 'ilike', $search)
                ->orWhere('sites.label', 'ilike', $search)
                ->orWhere('sites.reference', 'ilike', $search)
                ->orWhereRaw($this->promoterName().' ilike ?', [$search])));
    }

    /**
     * @return Builder<PaymentSchedule>
     */
    private function scheduleBase(): Builder
    {
        $flexible = DB::table('payment_installments')
            ->crossJoin(DB::raw('lateral jsonb_array_elements_text(payment_installments.schedule_ids) as covered(schedule_id)'))
            ->where('payment_installments.status', PaymentInstallmentStatus::APayer->value)
            ->selectRaw('covered.schedule_id::uuid as schedule_id, min(payment_installments.due_date) as next_due')
            ->groupBy('covered.schedule_id');

        return PaymentSchedule::query()
            ->join('missions', 'missions.id', '=', 'payment_schedules.mission_id')
            ->join('sites', 'sites.id', '=', 'missions.site_id')
            ->leftJoin('promoter_profiles', 'promoter_profiles.id', '=', 'sites.promoter_profile_id')
            ->leftJoin('users', 'users.id', '=', 'promoter_profiles.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'sites.organization_id')
            ->leftJoinSub($flexible, 'flexible', 'flexible.schedule_id', '=', 'payment_schedules.id');
    }

    private function promoterName(): string
    {
        return "coalesce(nullif(organizations.trade_name, ''), organizations.legal_name, concat_ws(' ', users.first_name, users.last_name))";
    }

    /**
     * Les statuts terminaux priment ; une échéance dépassée et non soldée est en retard.
     */
    private function stateSql(): string
    {
        $today = "'".today()->toDateString()."'::date";
        $due = 'coalesce(flexible.next_due, payment_schedules.due_date)';
        $final = collect([PaymentScheduleStatus::Payee, PaymentScheduleStatus::Contestee, PaymentScheduleStatus::Annulee, PaymentScheduleStatus::Remboursee])->map(fn (PaymentScheduleStatus $status) => "'{$status->value}'")->implode(', ');

        return "case when payment_schedules.status in ({$final}) then payment_schedules.status"
            ." when payment_schedules.status = 'en_retard' or {$due} < {$today} then 'en_retard'"
            ." when payment_schedules.status = 'partiellement_payee' then 'partiellement_payee'"
            ." when payment_schedules.status = 'exigible' or {$due} = {$today} then 'exigible'"
            ." else 'prevue' end";
    }
}
