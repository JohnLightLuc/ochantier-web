<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\ReplacementStatus;
use App\Models\Assignment;
use App\Models\Commune;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Affectations du Back Office : une ligne par ouvrier affecté à une mission, avec son remplacement en cours.
 */
class AssignmentDirectory
{
    public const STATUSES = ['confirmee', 'active', 'terminee', 'remplacee', 'annulee'];

    public const TODO = ['remplacements' => 'Remplacement en cours', 'demarrage' => 'Démarrent sous 7 jours', 'fin' => 'Terminent sous 7 jours'];

    public const OPEN = [AssignmentStatus::Confirmee, AssignmentStatus::Active];

    public const REPLACING = [ReplacementStatus::Signale, ReplacementStatus::Recherche, ReplacementStatus::Propose, ReplacementStatus::Valide];

    public const SORTS = ['start' => 'assignments.planned_start_date', 'end' => 'assignments.planned_end_date', 'status' => 'assignments.status', 'worker' => 'workers.last_name', 'created_at' => 'assignments.created_at'];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (AssignmentIndexRequest).
     * @return Builder<Assignment>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'start';
        $direction = ($filters['direction'] ?? ($sort === 'start' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('assignments.*')
            ->selectRaw('open_replacements.id as open_replacement_id')
            ->selectRaw('open_replacements.status as open_replacement_status')
            ->with(['worker', 'need.trade', 'mission.site.commune', 'mission.site.organization', 'mission.site.promoterProfile.user'])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('assignments.id');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = Assignment::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

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
        $needs = DB::table('assignments')->join('needs', 'needs.id', '=', 'assignments.need_id');
        $sites = DB::table('assignments')->join('missions', 'missions.id', '=', 'assignments.mission_id')->join('sites', 'sites.id', '=', 'missions.site_id')->whereNotNull('sites.commune_id');

        return [
            'trades' => Trade::query()->whereIn('id', $needs->select('needs.trade_id'))->orderBy('label')->get(['id', 'label'])->map(fn (Trade $trade) => ['id' => $trade->id, 'label' => $trade->label])->all(),
            'communes' => Commune::query()->whereIn('id', $sites->select('sites.commune_id'))->orderBy('name')->get(['id', 'name'])->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Assignment>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $open = array_map(fn (AssignmentStatus $status) => $status->value, self::OPEN);

        return $this->base()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('assignments.status', $status))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', workers.first_name, workers.last_name) ilike ?", [$search])
                ->orWhere('workers.reference', 'ilike', $search)
                ->orWhere('sites.label', 'ilike', $search)
                ->orWhere('sites.reference', 'ilike', $search)
                ->orWhere('missions.reference', 'ilike', $search)))
            ->when($filters['trade_id'] ?? null, fn (Builder $query, string $trade) => $query->where('needs.trade_id', $trade))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->where('sites.commune_id', $commune))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $site) => $query->where('sites.id', $site))
            ->when($filters['worker_id'] ?? null, fn (Builder $query, string $worker) => $query->where('assignments.worker_id', $worker))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'remplacements' => $query->whereNotNull('open_replacements.id'),
                'demarrage' => $query->where('assignments.status', AssignmentStatus::Confirmee->value)->whereBetween('assignments.planned_start_date', [today()->toDateString(), today()->addDays(7)->toDateString()]),
                'fin' => $query->whereIn('assignments.status', $open)->whereBetween('assignments.planned_end_date', [today()->toDateString(), today()->addDays(7)->toDateString()]),
            });
    }

    /**
     * @return Builder<Assignment>
     */
    private function base(): Builder
    {
        $replacing = array_map(fn (ReplacementStatus $status) => $status->value, self::REPLACING);
        $open = DB::table('replacements')->whereIn('status', $replacing)->selectRaw('distinct on (original_assignment_id) original_assignment_id, id, status')->orderBy('original_assignment_id')->orderByDesc('created_at');

        return Assignment::query()
            ->join('workers', 'workers.id', '=', 'assignments.worker_id')
            ->join('needs', 'needs.id', '=', 'assignments.need_id')
            ->join('missions', 'missions.id', '=', 'assignments.mission_id')
            ->join('sites', 'sites.id', '=', 'missions.site_id')
            ->leftJoinSub($open, 'open_replacements', 'open_replacements.original_assignment_id', '=', 'assignments.id');
    }
}
