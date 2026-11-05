<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\OfferStatus;
use App\Enums\SiteIncidentStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\Commune;
use App\Models\ConstructionType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chantiers du Back Office : effectif, retard, incidents et pointages à valider calculés en une requête, filtres, tri
 * et compteurs. Les chantiers archivés sortent de la liste courante.
 */
class SiteDirectory
{
    /**
     * Statuts d’un chantier dont l’équipe est constituée.
     */
    public const ACTIVE = [SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu];

    /**
     * Statuts d’un chantier encore en recherche d’ouvriers.
     */
    public const SEARCHING = [SiteStatus::Soumis, SiteStatus::Recherche, SiteStatus::Paiement];

    public const TODO = ['retard' => 'Chantiers en retard', 'effectif' => 'Effectif incomplet', 'incidents' => 'Avec incident ouvert', 'cloturer' => 'À clôturer', 'pointages' => 'Pointages à valider'];

    public const PERIODS = ['mois', 'a_venir', 'fin_60'];

    public const SORTS = [
        'label' => 'sites.label',
        'reference' => 'sites.reference',
        'start' => 'sites.planned_start_date',
        'end' => 'sites.planned_end_date',
        'staffing' => 'assigned_workers',
        'status' => 'sites.status',
        'submitted_at' => 'sites.submitted_at',
    ];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (SiteIndexRequest).
     * @return Builder<Site>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'submitted_at';
        $direction = ($filters['direction'] ?? ($sort === 'submitted_at' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('sites.*')
            ->selectRaw('coalesce(positions.total, 0) as required_workers')
            ->selectRaw('coalesce(staff.total, 0) as assigned_workers')
            ->selectRaw('coalesce(open_incidents.total, 0) as open_incidents_count')
            ->selectRaw('coalesce(pending_days.total, 0) as pending_days_count')
            ->with(['promoterProfile.user', 'organization', 'commune', 'constructionType'])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('sites.id');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, active: int, searching: int, archived: int, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = Site::query()->whereNull('archived_at')->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sum = fn (array $list): int => (int) collect($list)->sum(fn (SiteStatus $status) => $statuses->get($status->value, 0));

        return [
            'total' => (int) $statuses->sum(),
            'statuses' => collect(SiteStatus::cases())->mapWithKeys(fn (SiteStatus $status) => [$status->value => (int) $statuses->get($status->value, 0)])->all(),
            'active' => $sum(self::ACTIVE),
            'searching' => $sum(self::SEARCHING),
            'archived' => Site::query()->whereNotNull('archived_at')->count(),
            'todo' => collect(array_keys(self::TODO))->mapWithKeys(fn (string $list) => [$list => $this->filtered(['todo' => $list])->count()])->all(),
        ];
    }

    /**
     * Communes et types de construction des chantiers (listes des filtres).
     *
     * @return array{communes: list<array{id: string, name: string}>, construction_types: list<array{id: string, label: string}>}
     */
    public function options(): array
    {
        return [
            'communes' => Commune::query()->whereIn('id', Site::query()->whereNotNull('commune_id')->select('commune_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
            'construction_types' => ConstructionType::query()->whereIn('id', Site::query()->whereNotNull('construction_type_id')->select('construction_type_id'))->orderBy('label')->get(['id', 'label'])
                ->map(fn (ConstructionType $type) => ['id' => $type->id, 'label' => $type->label])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Site>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $active = array_map(fn (SiteStatus $status) => $status->value, self::ACTIVE);
        $late = fn (Builder $query) => $query->whereIn('sites.status', $active)->whereDate('sites.planned_end_date', '<', today());

        return $this->base()
            ->when(! ($filters['include_archived'] ?? false), fn (Builder $query) => ($filters['archived'] ?? false) ? $query->whereNotNull('sites.archived_at') : $query->whereNull('sites.archived_at'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('sites.status', $status))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('sites.label', 'ilike', $search)
                ->orWhere('sites.reference', 'ilike', $search)
                ->orWhereHas('promoterProfile.user', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search]))
                ->orWhereHas('organization', fn (Builder $query) => $query->where('trade_name', 'ilike', $search)->orWhere('legal_name', 'ilike', $search))))
            ->when($filters['promoter_id'] ?? null, fn (Builder $query, string $promoter) => $query->where('sites.promoter_profile_id', $promoter))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->where('sites.commune_id', $commune))
            ->when($filters['construction_type_id'] ?? null, fn (Builder $query, string $type) => $query->where('sites.construction_type_id', $type))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('sites.priority', $priority))
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => match ($period) {
                'mois' => $query->whereBetween('sites.planned_start_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()]),
                'a_venir' => $query->whereDate('sites.planned_start_date', '>', today()),
                'fin_60' => $query->whereBetween('sites.planned_end_date', [today()->toDateString(), today()->addDays(60)->toDateString()]),
            })
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'retard' => $late($query),
                'cloturer' => $late($query)->whereRaw('coalesce(pending_days.total, 0) = 0'),
                'effectif' => $query->whereIn('sites.status', [...$active, SiteStatus::Paiement->value])->whereRaw('coalesce(staff.total, 0) < coalesce(positions.total, 0)'),
                'incidents' => $query->whereRaw('coalesce(open_incidents.total, 0) > 0'),
                'pointages' => $query->whereRaw('coalesce(pending_days.total, 0) > 0'),
            });
    }

    /**
     * Chantiers et leurs agrégats.
     *
     * @return Builder<Site>
     */
    private function base(): Builder
    {
        $positions = DB::table('needs')
            ->join('offers', 'offers.id', '=', 'needs.offer_id')
            ->whereNull('needs.deleted_at')->whereNull('offers.deleted_at')
            ->where('offers.status', '!=', OfferStatus::Annulee->value)
            ->groupBy('needs.site_id')
            ->selectRaw('needs.site_id, sum(needs.headcount) as total');

        $staff = DB::table('assignments')
            ->join('missions', 'missions.id', '=', 'assignments.mission_id')
            ->whereIn('assignments.status', [AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value, AssignmentStatus::Terminee->value])
            ->groupBy('missions.site_id')
            ->selectRaw('missions.site_id, count(*) as total');

        $incidents = DB::table('site_incidents')->whereIn('status', [SiteIncidentStatus::Ouvert->value, SiteIncidentStatus::EnTraitement->value])->groupBy('site_id')->selectRaw('site_id, count(*) as total');

        $days = DB::table('work_days')
            ->join('missions', 'missions.id', '=', 'work_days.mission_id')
            ->where('work_days.status', WorkDayStatus::AValider->value)
            ->groupBy('missions.site_id')
            ->selectRaw('missions.site_id, count(*) as total');

        return Site::query()
            ->leftJoinSub($positions, 'positions', 'positions.site_id', '=', 'sites.id')
            ->leftJoinSub($staff, 'staff', 'staff.site_id', '=', 'sites.id')
            ->leftJoinSub($incidents, 'open_incidents', 'open_incidents.site_id', '=', 'sites.id')
            ->leftJoinSub($days, 'pending_days', 'pending_days.site_id', '=', 'sites.id');
    }
}
