<?php

namespace App\Support\Admin;

use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceCorrectionStatus;
use App\Enums\DisputeStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\WorkDayStatus;
use App\Enums\WorkerPayoutBatchStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\Dispute;
use App\Models\Worker;
use App\Models\WorkerPayout;
use App\Models\WorkerPayoutBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Consultation des reversements : ce qui est à reverser (journées validées, non payées, dont l’échéance du promoteur
 * est réglée), les reversements et les lots.
 */
class PayoutDirectory
{
    public const PAYABLE_STATES = ['payable', 'blocked'];

    /**
     * Litiges qui bloquent un reversement tant qu’ils ne sont pas tranchés.
     */
    private const OPEN_DISPUTES = [DisputeStatus::Ouvert, DisputeStatus::EnInstruction, DisputeStatus::EnRecours];

    /**
     * Une ligne par échéance réglée et par affectation.
     *
     * @param  array<string, mixed>  $filters
     */
    public function payable(array $filters): QueryBuilder
    {
        $disputes = implode(',', array_map(fn (DisputeStatus $status) => "'{$status->value}'", self::OPEN_DISPUTES));
        $anomalies = "'".AttendanceAnomalyStatus::Ouverte->value."','".AttendanceAnomalyStatus::EnTraitement->value."'";
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        $rows = DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->join('payment_schedules as s', fn ($join) => $join->on('s.mission_id', '=', 'd.mission_id')->whereColumn('d.work_date', '>=', 's.period_start')->whereColumn('d.work_date', '<=', 's.period_end'))
            ->join('missions as m', 'm.id', '=', 'd.mission_id')
            ->join('sites as t', 't.id', '=', 'm.site_id')
            ->join('workers as w', 'w.id', '=', 'd.worker_id')
            ->where('d.status', WorkDayStatus::Valide->value)
            ->where('d.is_locked', false)
            ->where('s.status', PaymentScheduleStatus::Payee->value)
            ->whereRaw("(s.schedule_type <> '".PaymentScheduleType::Regularisation->value."' or ".self::outsidePaidWeek('d.mission_id', 'd.work_date').')')
            ->whereNotNull('a.worker_net_daily_rate')
            ->when($filters['schedule_id'] ?? null, fn (QueryBuilder $query, string $id) => $query->where('s.id', $id))
            ->when($filters['assignment_id'] ?? null, fn (QueryBuilder $query, string $id) => $query->where('a.id', $id))
            ->when($filters['site_id'] ?? null, fn (QueryBuilder $query, string $id) => $query->where('t.id', $id))
            ->when($search, fn (QueryBuilder $query) => $query->where(fn (QueryBuilder $query) => $query
                ->whereRaw("concat_ws(' ', w.first_name, w.last_name) ilike ?", [$search])
                ->orWhere('w.reference', 'ilike', $search)
                ->orWhere('t.label', 'ilike', $search)
                ->orWhere('s.reference', 'ilike', $search)))
            ->groupBy('s.id', 's.reference', 's.sequence', 's.period_start', 's.period_end', 'a.id', 'a.promoter_daily_rate', 'a.worker_net_daily_rate', 'm.id', 'm.reference', 't.id', 't.label', 'w.id', 'w.reference', 'w.first_name', 'w.last_name')
            ->select(['s.id as schedule_id', 's.reference as schedule_reference', 's.sequence', 's.period_start', 's.period_end', 'a.id as assignment_id', 'a.worker_net_daily_rate', 'm.id as mission_id', 'm.reference as mission_reference', 't.id as site_id', 't.label as site_label', 'w.id as worker_id', 'w.reference as worker_reference', 'w.first_name', 'w.last_name'])
            ->selectRaw('count(*) as days_count, min(d.work_date) as first_day, max(d.work_date) as last_day')
            ->selectRaw('coalesce(a.promoter_daily_rate, a.worker_net_daily_rate) as promoter_daily_rate')
            ->selectRaw("exists (select 1 from disputes x where x.status in ({$disputes}) and (x.assignment_id = a.id or (x.assignment_id is null and x.mission_id = m.id))) as has_dispute")
            ->selectRaw("bool_or(exists (select 1 from attendance_corrections c where c.work_day_id = d.id and c.status = '".AttendanceCorrectionStatus::Demandee->value."')) as has_correction")
            ->selectRaw("bool_or(exists (select 1 from attendance_anomalies n where n.work_day_id = d.id and n.status in ({$anomalies}))) as has_anomaly")
            ->selectRaw("(select p.account_number_masked from payment_methods p where p.owner_type = 'worker' and p.owner_id = w.id and p.is_default and p.status = '".PaymentMethodStatus::Actif->value."' and p.verified_at is not null and p.deleted_at is null) as method_number");

        $blocked = 'has_dispute or has_correction or has_anomaly or method_number is null';

        return DB::query()->fromSub($rows, 'p')
            ->when(($filters['state'] ?? null) === 'payable', fn (QueryBuilder $query) => $query->whereRaw("not ({$blocked})"))
            ->when(($filters['state'] ?? null) === 'blocked', fn (QueryBuilder $query) => $query->whereRaw($blocked))
            ->orderBy('period_start')
            ->orderBy('schedule_id')
            ->orderBy('assignment_id');
    }

    /**
     * Une régularisation couvre toute la mission : elle ne libère que les journées qu’aucune échéance payée ne couvre.
     */
    public static function outsidePaidWeek(string $mission, string $day): string
    {
        return "not exists (select 1 from payment_schedules w where w.mission_id = {$mission} and w.schedule_type <> '".PaymentScheduleType::Regularisation->value."' and w.status = '".PaymentScheduleStatus::Payee->value."' and {$day} between w.period_start and w.period_end)";
    }

    /**
     * @return array<string, mixed>
     */
    public function payableRow(object $row): array
    {
        $days = (int) $row->days_count;
        $gross = $days * (int) $row->promoter_daily_rate;
        $net = $days * (int) $row->worker_net_daily_rate;

        return [
            'key' => $row->schedule_id.':'.$row->assignment_id,
            'schedule_id' => $row->schedule_id,
            'assignment_id' => $row->assignment_id,
            'schedule' => ['id' => $row->schedule_id, 'reference' => $row->schedule_reference, 'sequence' => (int) $row->sequence, 'period_start' => substr((string) $row->period_start, 0, 10), 'period_end' => substr((string) $row->period_end, 0, 10)],
            'worker' => ['id' => $row->worker_id, 'reference' => $row->worker_reference, 'full_name' => trim($row->first_name.' '.$row->last_name)],
            'site' => ['id' => $row->site_id, 'label' => $row->site_label],
            'mission' => ['id' => $row->mission_id, 'reference' => $row->mission_reference],
            'first_day' => substr((string) $row->first_day, 0, 10),
            'last_day' => substr((string) $row->last_day, 0, 10),
            'days' => $days,
            'gross_amount' => $gross,
            'commission_amount' => $gross - $net,
            'net_amount' => $net,
            'number' => $row->method_number,
            'blockers' => array_values(array_filter([
                $row->has_dispute ? 'Litige ouvert' : null,
                $row->has_correction ? 'Correction de pointage en attente' : null,
                $row->has_anomaly ? 'Anomalie de pointage ouverte' : null,
                $row->method_number ? null : 'Aucun numéro Mobile Money confirmé',
            ])),
        ];
    }

    /**
     * @return array{payable: int, blocked: int, payable_amount: int, blocked_amount: int}
     */
    public function payableCounts(): array
    {
        $rows = $this->payable([])->get()->map(fn (object $row) => $this->payableRow($row));
        [$blocked, $payable] = $rows->partition(fn (array $row) => $row['blockers'] !== []);

        return ['payable' => $payable->count(), 'blocked' => $blocked->count(), 'payable_amount' => (int) $payable->sum('net_amount'), 'blocked_amount' => (int) $blocked->sum('net_amount')];
    }

    /**
     * Un litige ouvert empêche d’envoyer ce reversement.
     */
    public function hasOpenDispute(WorkerPayout $payout): bool
    {
        $assignments = $payout->workDays()->distinct()->pluck('assignment_id');

        return Dispute::query()
            ->whereIn('status', self::OPEN_DISPUTES)
            ->where(fn (Builder $query) => $query->whereIn('assignment_id', $assignments)->orWhere(fn (Builder $query) => $query->whereNull('assignment_id')->where('mission_id', $payout->mission_id)))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<WorkerPayout>
     */
    public function payouts(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return WorkerPayout::query()
            ->with(['worker', 'mission.site', 'batch', 'paymentMethod.operator'])
            ->withCount('workDays')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['batch_id'] ?? null, fn (Builder $query, string $id) => $query->where('batch_id', $id))
            ->when($filters['worker_id'] ?? null, fn (Builder $query, string $id) => $query->where('worker_id', $id))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas('mission', fn (Builder $query) => $query->where('site_id', $id)))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhereHas('worker', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])->orWhere('reference', 'ilike', $search))
                ->orWhereHas('mission.site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->latest()
            ->orderBy('id');
    }

    /**
     * @return array{statuses: array<string, int>, paid_amount: int}
     */
    public function payoutCounts(): array
    {
        $rows = WorkerPayout::query()->toBase()->selectRaw('status, count(*) as total, sum(net_amount) as amount')->groupBy('status')->get()->keyBy('status');

        return [
            'statuses' => collect(WorkerPayoutStatus::cases())->mapWithKeys(fn (WorkerPayoutStatus $status) => [$status->value => (int) ($rows->get($status->value)->total ?? 0)])->all(),
            'paid_amount' => (int) ($rows->get(WorkerPayoutStatus::Confirme->value)->amount ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<WorkerPayoutBatch>
     */
    public function batches(array $filters): Builder
    {
        return WorkerPayoutBatch::query()
            ->with(['createdByAdmin', 'approvedByAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()
            ->orderBy('id');
    }

    /**
     * @return array<string, int>
     */
    public function batchCounts(): array
    {
        $rows = WorkerPayoutBatch::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(WorkerPayoutBatchStatus::cases())->mapWithKeys(fn (WorkerPayoutBatchStatus $status) => [$status->value => (int) $rows->get($status->value, 0)])->all();
    }

    /**
     * Nombre de numéros à confirmer, pour l’onglet du Back Office.
     */
    public function pendingMethodsCount(): int
    {
        return DB::table('payment_methods')->where('owner_type', (new Worker)->getMorphClass())->where('status', PaymentMethodStatus::Actif->value)->whereNull('verified_at')->whereNull('deleted_at')->count();
    }
}
