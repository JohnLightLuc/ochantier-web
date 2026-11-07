<?php

namespace App\Support\Admin;

use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Enums\WorkDayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres de Finance, calculés sur les flux réels d’une période.
 *
 * Commission acquise : sur chaque journée validée, Y (commission promoteur) et Z (écart entre le tarif facturé et le net
 * de l’ouvrier). Commission encaissée : part de commission de l’argent reçu, au taux figé de la mission, moins la part
 * des remboursements exécutés. Sur les mêmes missions, l’écart entre les deux montre ce qui reste à recevoir.
 */
class FinanceReport
{
    public const GROUPS = ['mission', 'site', 'promoter'];

    private const COLUMNS = ['days', 'base', 'y_amount', 'z_amount', 'earned', 'received', 'collected', 'refunded', 'remaining'];

    public function __construct(private PaymentDirectory $payments) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $flows = $this->flows($from, $to);
        $totals = DB::query()->fromSub($this->journal($from, $to), 'j')->selectRaw('coalesce(sum(y_amount), 0) as y_amount, coalesce(sum(z_amount), 0) as z_amount, coalesce(sum(earned), 0) as earned, coalesce(sum(collected), 0) as collected, coalesce(sum(refunded), 0) as refunded, coalesce(sum(remaining), 0) as remaining')->first();
        $sum = fn (string $column): int => (int) $totals->{$column};
        $collected = $sum('collected') - $sum('refunded');
        $schedules = $this->payments->scheduleCounts();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'inflows' => [
                'missions' => $flows['acompte'] + $flows['echeance'],
                'subscriptions' => $flows['abonnement'],
                'total' => $flows['acompte'] + $flows['echeance'] + $flows['abonnement'],
                'operator_fees' => $flows['fees'],
            ],
            'outflows' => [
                'workers' => $flows['reversement_ouvrier'],
                'commercials' => $flows['gain_commercial'],
                'refunds' => $flows['remboursement'],
                'total' => $flows['reversement_ouvrier'] + $flows['gain_commercial'] + $flows['remboursement'],
            ],
            'net_cash' => $flows['acompte'] + $flows['echeance'] + $flows['abonnement'] - $flows['reversement_ouvrier'] - $flows['gain_commercial'] - $flows['remboursement'],
            'commissions' => [
                'promoter_earned' => $sum('y_amount'),
                'worker_earned' => $sum('z_amount'),
                'earned' => $sum('earned'),
                'collected' => $collected,
                'remaining' => $sum('remaining'),
            ],
            'revenue' => $collected + $flows['abonnement'],
            'receivables' => ['late' => $schedules['late_amount'], 'open' => $schedules['open_amount']],
        ];
    }

    /**
     * Journal des commissions, par mission, chantier ou promoteur. Les missions sans mouvement sur la période et sans
     * reste à recevoir n’y figurent pas.
     *
     * @param  array<string, mixed>  $filters  group, search
     */
    public function commissions(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): Builder
    {
        $group = $filters['group'] ?? 'mission';
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $query = DB::query()->fromSub($this->journal($from, $to), 'j')
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhere('site_label', 'ilike', $search)
                ->orWhere('promoter_name', 'ilike', $search)));

        if ($group === 'mission') {
            return $query->orderByDesc('earned')->orderBy('reference');
        }

        [$id, $label] = $group === 'site' ? ['site_id', 'site_label'] : ['promoter_id', 'promoter_name'];

        return $query
            ->groupBy($id, $label)
            ->select(["{$id} as id", "{$label} as label"])
            ->selectRaw('count(*) as missions')
            ->selectRaw(collect(self::COLUMNS)->map(fn (string $column) => "coalesce(sum({$column}), 0) as {$column}")->implode(', '))
            ->orderByDesc('earned')
            ->orderBy($label);
    }

    /**
     * Une ligne par mission : mouvements de la période, et reste à recevoir toutes périodes confondues.
     */
    private function journal(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('missions as m')
            ->join('sites as t', 't.id', '=', 'm.site_id')
            ->join('promoter_profiles as pp', 'pp.id', '=', 't.promoter_profile_id')
            ->join('users as u', 'u.id', '=', 'pp.user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 't.organization_id')
            ->leftJoinSub($this->earned($from, $to), 'e', 'e.mission_id', '=', 'm.id')
            ->leftJoinSub($this->received($from, $to), 'r', 'r.mission_id', '=', 'm.id')
            ->leftJoinSub($this->refunds($from, $to), 'b', 'b.mission_id', '=', 'm.id')
            ->leftJoinSub($this->earned(), 'ea', 'ea.mission_id', '=', 'm.id')
            ->leftJoinSub($this->received(), 'ra', 'ra.mission_id', '=', 'm.id')
            ->leftJoinSub($this->refunds(), 'ba', 'ba.mission_id', '=', 'm.id')
            ->where(fn (Builder $query) => $query->whereNotNull('e.mission_id')->orWhereNotNull('r.mission_id')->orWhereNotNull('b.mission_id')->orWhereRaw('coalesce(ea.earned, 0) > coalesce(ra.collected, 0) - coalesce(ba.refunded, 0)'))
            ->select(['m.id', 'm.reference', 't.id as site_id', 't.label as site_label', 'pp.id as promoter_id'])
            ->selectRaw("coalesce(nullif(o.trade_name, ''), o.legal_name, nullif(concat_ws(' ', u.first_name, u.last_name), ''), u.phone_number) as promoter_name")
            ->selectRaw('coalesce(e.days, 0) as days, coalesce(e.base, 0) as base, coalesce(e.y_amount, 0) as y_amount, coalesce(e.z_amount, 0) as z_amount, coalesce(e.earned, 0) as earned')
            ->selectRaw('coalesce(r.received, 0) as received, coalesce(r.collected, 0) as collected, coalesce(b.refunded, 0) as refunded')
            ->selectRaw('greatest(coalesce(ea.earned, 0) - coalesce(ra.collected, 0) + coalesce(ba.refunded, 0), 0) as remaining');
    }

    /**
     * Commissions acquises sur les journées validées.
     */
    private function earned(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        $base = 'coalesce(a.promoter_daily_rate, a.worker_net_daily_rate, 0)';
        $y = "round(sum({$base} * p.y_rate / 100))";
        $z = "sum({$base} - coalesce(a.worker_net_daily_rate, {$base}))";

        return DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->join('missions as em', 'em.id', '=', 'd.mission_id')
            ->join('pricing_snapshots as p', 'p.id', '=', 'em.pricing_snapshot_id')
            ->where('d.status', WorkDayStatus::Valide->value)
            ->when($from, fn (Builder $query) => $query->whereBetween('d.work_date', [$from->toDateString(), $to->toDateString()]))
            ->groupBy('d.mission_id')
            ->select('d.mission_id')
            ->selectRaw("count(*) as days, sum({$base}) as base, {$y} as y_amount, {$z} as z_amount, {$y} + {$z} as earned");
    }

    /**
     * Argent reçu, et sa part de commission au taux figé de la mission.
     */
    private function received(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return DB::table('payment_allocations as pa')
            ->join('payment_transactions as tx', 'tx.id', '=', 'pa.transaction_id')
            ->join('payment_schedules as s', 's.id', '=', 'pa.payment_schedule_id')
            ->join('missions as rm', 'rm.id', '=', 's.mission_id')
            ->join('pricing_snapshots as p', 'p.id', '=', 'rm.pricing_snapshot_id')
            ->where('tx.status', PaymentTransactionStatus::Reussie->value)
            ->when($from, fn (Builder $query) => $query->whereBetween('tx.completed_at', [$from->startOfDay(), $to->endOfDay()]))
            ->groupBy('s.mission_id')
            ->select('s.mission_id')
            ->selectRaw('sum(pa.amount) as received')
            ->selectRaw('round(sum(pa.amount * '.$this->share().')) as collected');
    }

    /**
     * Part de commission des remboursements exécutés.
     */
    private function refunds(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return DB::table('refunds as x')
            ->join('missions as bm', 'bm.id', '=', 'x.mission_id')
            ->join('pricing_snapshots as p', 'p.id', '=', 'bm.pricing_snapshot_id')
            ->where('x.status', RefundStatus::Execute->value)
            ->when($from, fn (Builder $query) => $query->whereBetween('x.executed_at', [$from->startOfDay(), $to->endOfDay()]))
            ->groupBy('x.mission_id')
            ->select('x.mission_id')
            ->selectRaw('round(sum(x.amount * '.$this->share().')) as refunded');
    }

    private function share(): string
    {
        return '(p.promoter_commission_amount + p.worker_commission_amount)::numeric / nullif(p.total_promoter_amount, 0)';
    }

    /**
     * Opérations réussies de la période, par nature.
     *
     * @return array<string, int>
     */
    private function flows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('payment_transactions')
            ->where('status', PaymentTransactionStatus::Reussie->value)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('purpose')
            ->selectRaw('purpose, sum(amount) as amount')
            ->pluck('amount', 'purpose');

        return [
            ...collect(PaymentTransactionPurpose::cases())->mapWithKeys(fn (PaymentTransactionPurpose $purpose) => [$purpose->value => (int) $rows->get($purpose->value, 0)])->all(),
            'fees' => (int) DB::table('payment_transactions')
                ->where('status', PaymentTransactionStatus::Reussie->value)
                ->where('direction', PaymentTransactionDirection::Encaissement->value)
                ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
                ->sum('fees_amount'),
        ];
    }
}
