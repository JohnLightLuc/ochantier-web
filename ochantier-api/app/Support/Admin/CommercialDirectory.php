<?php

namespace App\Support\Admin;

use App\Enums\CommercialEarningStatus;
use App\Enums\CommercialObjectiveIndicator;
use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialProfile;
use App\Models\CommercialZone;
use App\Models\Commune;
use App\Support\Commercial\CommercialEarnings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Commerciaux du Back Office : statut du compte, indicateurs du mois (mêmes règles que l’application Commercial :
 * ouvriers validés, objectif, commissions), filtres, tri et compteurs, calculés en une requête.
 */
class CommercialDirectory
{
    public const STATUSES = ['en_attente_activation' => 'En attente d’activation', 'actif' => 'Actif', 'suspendu' => 'Suspendu', 'termine' => 'Terminé'];

    /**
     * Statut affiché : réunit le contrat (commercial_profiles) et le compte de connexion (users).
     */
    public const STATUS_SQL = "case
        when commercial_profiles.status = 'termine' or users.status = 'desactive' then 'termine'
        when commercial_profiles.status = 'suspendu' or users.status = 'suspendu' then 'suspendu'
        when users.status = 'en_attente_activation' or users.must_change_password then 'en_attente_activation'
        else 'actif' end";

    /**
     * Listes « à traiter » : objectif atteint, commerciaux à relancer, commissions acquises non versées.
     */
    public const TODO = ['objectifs' => 'Objectifs atteints', 'relances' => 'Commerciaux à relancer', 'gains' => 'Commissions à verser'];

    public const PERIODS = ['semaine' => 7, 'mois' => 30];

    /**
     * Jours sans activité au-delà desquels un commercial est à relancer.
     */
    public const INACTIVE_DAYS = 30;

    private const ACTIVITY_SQL = 'greatest(users.last_login_at, activity.last_prospect_at, workers_stats.last_submitted_at)';

    public const SORTS = [
        'name' => 'users.last_name',
        'zone' => 'primary_zone',
        'validated' => 'month_validated',
        'validation_rate' => 'validation_rate',
        'objective' => 'objective_percent',
        'commissions' => 'month_commissions',
        'status' => 'account_status',
        'last_activity' => 'last_activity_at',
    ];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (CommercialIndexRequest).
     * @return Builder<CommercialProfile>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('commercial_profiles.*')
            ->selectRaw(self::STATUS_SQL.' as account_status')
            ->selectRaw(self::ACTIVITY_SQL.' as last_activity_at')
            ->selectRaw('coalesce(workers_stats.total, 0) as total_workers')
            ->selectRaw('coalesce(workers_stats.month_registered, 0) as month_registered')
            ->selectRaw('coalesce(workers_stats.month_validated, 0) as month_validated')
            ->selectRaw('case when workers_stats.month_decided > 0 then round(workers_stats.month_accepted * 100.0 / workers_stats.month_decided) end as validation_rate')
            ->selectRaw('objectives.target_value as objective_target')
            ->selectRaw('objectives.achieved as objective_achieved')
            ->selectRaw('least(100, round(objectives.achieved * 100.0 / objectives.target_value)) as objective_percent')
            ->selectRaw('coalesce(earnings.month_commissions, 0) as month_commissions')
            ->selectRaw('coalesce(earnings.unpaid, 0) as unpaid_commissions')
            ->selectSub(fn (QueryBuilder $query) => $query->select('communes.name')->from('commercial_zones')
                ->join('communes', 'communes.id', '=', 'commercial_zones.commune_id')
                ->whereColumn('commercial_zones.commercial_id', 'commercial_profiles.id')
                ->where('commercial_zones.is_primary', true)
                ->whereDate('commercial_zones.effective_from', '<=', today())
                ->where(fn (QueryBuilder $query) => $query->whereNull('commercial_zones.effective_to')->orWhereDate('commercial_zones.effective_to', '>=', today()))
                ->limit(1), 'primary_zone')
            ->with(['user', 'supervisor.user', 'commercialZones' => fn ($query) => $query->whereDate('effective_from', '<=', today())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
                ->with('commune')->orderByDesc('is_primary')])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('commercial_profiles.id');
    }

    /**
     * Compteurs par statut et par liste « à traiter », ouvriers enregistrés et commissions à verser.
     *
     * @return array{total: int, statuses: array<string, int>, todo: array<string, int>, workers: int, unpaid: int}
     */
    public function counts(): array
    {
        $statuses = $this->base()->toBase()->selectRaw(self::STATUS_SQL.' as account_status, count(*) as total')->groupByRaw('1')->pluck('total', 'account_status');
        $totals = $this->base()->toBase()->selectRaw('coalesce(sum(workers_stats.total), 0) as workers, coalesce(sum(earnings.unpaid), 0) as unpaid')->first();

        return [
            'total' => (int) $statuses->sum(),
            'statuses' => collect(array_keys(self::STATUSES))->mapWithKeys(fn (string $status) => [$status => (int) $statuses->get($status, 0)])->all(),
            'todo' => collect(array_keys(self::TODO))->mapWithKeys(fn (string $list) => [$list => $this->filtered(['todo' => $list])->count()])->all(),
            'workers' => (int) $totals->workers,
            'unpaid' => (int) $totals->unpaid,
        ];
    }

    /**
     * Communes attribuées et superviseurs (listes des filtres).
     *
     * @return array{communes: list<array{id: string, name: string}>, supervisors: list<array{id: string, full_name: string}>}
     */
    public function options(): array
    {
        $supervisors = CommercialProfile::query()->where(fn (Builder $query) => $query->where('is_supervisor', true)->orWhereIn('id', CommercialProfile::query()->whereNotNull('supervisor_id')->select('supervisor_id')))->with('user')->get();

        return [
            'communes' => Commune::query()->whereIn('id', CommercialZone::query()->select('commune_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
            'supervisors' => $supervisors->map(fn (CommercialProfile $profile) => ['id' => $profile->id, 'full_name' => trim($profile->user->first_name.' '.$profile->user->last_name)])
                ->sortBy('full_name')->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CommercialProfile>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $inactiveSince = now()->subDays(self::INACTIVE_DAYS);

        return $this->base()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->whereRaw(self::STATUS_SQL.' = ?', [$status]))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('users.first_name', 'ilike', $search)
                ->orWhere('users.last_name', 'ilike', $search)
                ->orWhereRaw("concat_ws(' ', users.first_name, users.last_name) ilike ?", [$search])
                ->orWhere('commercial_profiles.employee_number', 'ilike', $search)
                ->orWhere('users.email', 'ilike', $search)
                ->orWhere('users.phone_number', 'ilike', str_replace(' ', '', $search))))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->whereHas('commercialZones', fn (Builder $query) => $query->where('commune_id', $commune)
                ->whereDate('effective_from', '<=', today())
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))))
            ->when($filters['supervisor_id'] ?? null, fn (Builder $query, string $supervisor) => $query->where('commercial_profiles.supervisor_id', $supervisor))
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $period === 'inactifs'
                ? $query->whereRaw('coalesce('.self::ACTIVITY_SQL.", '-infinity') < ?", [$inactiveSince])
                : $query->whereRaw(self::ACTIVITY_SQL.' >= ?', [now()->subDays(self::PERIODS[$period])]))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'objectifs' => $query->whereColumn('objectives.achieved', '>=', 'objectives.target_value'),
                'relances' => $query->where(fn (Builder $query) => $query
                    ->whereRaw(self::STATUS_SQL." = 'en_attente_activation'")
                    ->orWhereRaw('('.self::STATUS_SQL." = 'actif' and coalesce(".self::ACTIVITY_SQL.", '-infinity') < ?)", [$inactiveSince])),
                'gains' => $query->where('earnings.unpaid', '>', 0),
            });
    }

    /**
     * Commerciaux, leur compte et les agrégats du mois (ouvriers, activité, objectif, commissions).
     *
     * @return Builder<CommercialProfile>
     */
    private function base(): Builder
    {
        $month = CarbonImmutable::today()->startOfMonth();
        $end = $month->endOfMonth();
        $in = fn (string $column): string => "{$column} between '{$month->toDateTimeString()}' and '{$end->toDateTimeString()}'";
        $valid = WorkerVerificationStatus::Valide->value;
        $refused = WorkerVerificationStatus::Refuse->value;
        $draft = WorkerVerificationStatus::Brouillon->value;

        $workers = DB::table('workers')
            ->whereNull('deleted_at')->whereNotNull('origin_commercial_id')
            ->groupBy('origin_commercial_id')
            ->select('origin_commercial_id')
            ->selectRaw("count(*) filter (where verification_status <> '{$draft}') as total")
            ->selectRaw("count(*) filter (where verification_status <> '{$draft}' and {$in('submitted_at')}) as month_registered")
            ->selectRaw("count(*) filter (where verification_status = '{$valid}' and {$in('validated_at')}) as month_validated")
            ->selectRaw("count(*) filter (where verification_status = '{$valid}' and {$in('submitted_at')}) as month_accepted")
            ->selectRaw("count(*) filter (where verification_status in ('{$valid}', '{$refused}') and {$in('submitted_at')}) as month_decided")
            ->selectRaw('max(submitted_at) as last_submitted_at');

        $activity = DB::table('prospects')->whereNull('deleted_at')->groupBy('commercial_id')->select('commercial_id')->selectRaw('max(created_at) as last_prospect_at');

        $objectives = DB::table('commercial_objectives as objective')
            ->where('objective.indicator', CommercialObjectiveIndicator::OuvriersValides->value)
            ->whereDate('objective.period_start', '<=', $end)
            ->whereDate('objective.period_end', '>=', $month)
            ->selectRaw('distinct on (objective.commercial_id) objective.commercial_id, objective.target_value')
            ->selectSub(fn (QueryBuilder $query) => $query->selectRaw('count(*)')->from('workers')
                ->whereNull('workers.deleted_at')
                ->whereColumn('workers.origin_commercial_id', 'objective.commercial_id')
                ->where('workers.verification_status', $valid)
                ->whereRaw('workers.validated_at >= objective.period_start')
                ->whereRaw("workers.validated_at < objective.period_end + interval '1 day'"), 'achieved')
            ->orderBy('objective.commercial_id')
            ->orderByDesc('objective.period_start');

        $confirmed = $this->quoted(CommercialEarnings::CONFIRMED);
        $unpaid = $this->quoted([CommercialEarningStatus::Acquis, CommercialEarningStatus::Approuve]);

        $earnings = DB::table('commercial_earnings')
            ->groupBy('commercial_id')
            ->select('commercial_id')
            ->selectRaw("coalesce(sum(amount) filter (where status in ({$confirmed}) and {$in('event_at')}), 0) as month_commissions")
            ->selectRaw("coalesce(sum(amount) filter (where status in ({$unpaid})), 0) as unpaid");

        return CommercialProfile::query()
            ->join('users', 'users.id', '=', 'commercial_profiles.user_id')
            ->leftJoinSub($workers, 'workers_stats', 'workers_stats.origin_commercial_id', '=', 'commercial_profiles.id')
            ->leftJoinSub($activity, 'activity', 'activity.commercial_id', '=', 'commercial_profiles.id')
            ->leftJoinSub($objectives, 'objectives', 'objectives.commercial_id', '=', 'commercial_profiles.id')
            ->leftJoinSub($earnings, 'earnings', 'earnings.commercial_id', '=', 'commercial_profiles.id');
    }

    /**
     * @param  list<CommercialEarningStatus>  $statuses
     */
    private function quoted(array $statuses): string
    {
        return implode(', ', array_map(fn (CommercialEarningStatus $status) => "'{$status->value}'", $statuses));
    }
}
