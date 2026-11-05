<?php

namespace App\Support\Admin;

use App\Enums\AttendanceAnomalyStatus;
use App\Enums\WorkDayStatus;
use App\Models\Commune;
use App\Models\Trade;
use App\Models\WorkDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pointages du Back Office : une ligne par ouvrier et par jour pointé, avec ses anomalies ouvertes.
 */
class AttendanceDirectory
{
    public const STATUSES = ['en_cours', 'a_valider', 'valide', 'rejete', 'absent', 'annule'];

    public const TODO = ['retard' => 'À valider en retard', 'incomplets' => 'Sans départ', 'absences' => 'Absences', 'hors_zone' => 'Hors zone', 'anomalies' => 'Anomalie ouverte'];

    public const SORTS = ['date' => 'work_days.work_date', 'worker' => 'workers.last_name', 'status' => 'work_days.status', 'minutes' => 'work_days.worked_minutes'];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (AttendanceIndexRequest).
     * @return Builder<WorkDay>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'date';
        $direction = ($filters['direction'] ?? ($sort === 'date' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('work_days.*')
            ->selectRaw('coalesce(open_anomalies.total, 0) as open_anomalies')
            ->with(['worker', 'assignment.need.trade', 'mission.site.commune', 'mission.site.organization', 'mission.site.promoterProfile.user', 'arrivalEvent', 'departureEvent'])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('work_days.id');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = WorkDay::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $statuses->sum(),
            'statuses' => collect(self::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) $statuses->get($status, 0)])->all(),
            'todo' => collect(array_keys(self::TODO))->mapWithKeys(fn (string $list) => [$list => $this->filtered(['todo' => $list])->count()])->all(),
        ];
    }

    /**
     * @return array{trades: list<array{id: string, label: string}>, communes: list<array{id: string, name: string}>}
     */
    public function options(): array
    {
        $trades = DB::table('work_days')->join('assignments', 'assignments.id', '=', 'work_days.assignment_id')->join('needs', 'needs.id', '=', 'assignments.need_id');
        $communes = DB::table('work_days')->join('missions', 'missions.id', '=', 'work_days.mission_id')->join('sites', 'sites.id', '=', 'missions.site_id')->whereNotNull('sites.commune_id');

        return [
            'trades' => Trade::query()->whereIn('id', $trades->select('needs.trade_id'))->orderBy('label')->get(['id', 'label'])->map(fn (Trade $trade) => ['id' => $trade->id, 'label' => $trade->label])->all(),
            'communes' => Commune::query()->whereIn('id', $communes->select('sites.commune_id'))->orderBy('name')->get(['id', 'name'])->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<WorkDay>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $today = today()->toDateString();

        return $this->base()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('work_days.status', $status))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', workers.first_name, workers.last_name) ilike ?", [$search])
                ->orWhere('workers.reference', 'ilike', $search)
                ->orWhere('sites.label', 'ilike', $search)
                ->orWhere('sites.reference', 'ilike', $search)))
            ->when($filters['trade_id'] ?? null, fn (Builder $query, string $trade) => $query->where('needs.trade_id', $trade))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->where('sites.commune_id', $commune))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $site) => $query->where('sites.id', $site))
            ->when($filters['worker_id'] ?? null, fn (Builder $query, string $worker) => $query->where('work_days.worker_id', $worker))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('work_days.work_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('work_days.work_date', '<=', $date))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'retard' => $query->where('work_days.status', WorkDayStatus::AValider->value)->where('work_days.work_date', '<', $today),
                'incomplets' => $query->where('work_days.status', WorkDayStatus::EnCours->value)->where('work_days.work_date', '<', $today),
                'absences' => $query->where('work_days.status', WorkDayStatus::Absent->value),
                'hors_zone' => $query->where(fn (Builder $query) => $query->where('arrival.out_of_zone', true)->orWhere('departure.out_of_zone', true)),
                'anomalies' => $query->whereNotNull('open_anomalies.total'),
            });
    }

    /**
     * @return Builder<WorkDay>
     */
    private function base(): Builder
    {
        $anomalies = DB::table('attendance_anomalies')->whereIn('status', [AttendanceAnomalyStatus::Ouverte->value, AttendanceAnomalyStatus::EnTraitement->value])->whereNotNull('work_day_id')->selectRaw('work_day_id, count(*) as total')->groupBy('work_day_id');

        return WorkDay::query()
            ->join('workers', 'workers.id', '=', 'work_days.worker_id')
            ->join('assignments', 'assignments.id', '=', 'work_days.assignment_id')
            ->join('needs', 'needs.id', '=', 'assignments.need_id')
            ->join('missions', 'missions.id', '=', 'work_days.mission_id')
            ->join('sites', 'sites.id', '=', 'missions.site_id')
            ->leftJoin('attendance_events as arrival', 'arrival.id', '=', 'work_days.arrival_event_id')
            ->leftJoin('attendance_events as departure', 'departure.id', '=', 'work_days.departure_event_id')
            ->leftJoinSub($anomalies, 'open_anomalies', 'open_anomalies.work_day_id', '=', 'work_days.id');
    }
}
