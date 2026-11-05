<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Models\Commune;
use App\Models\Offer;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Besoins du Back Office : une ligne par offre (la réservation du promoteur), avec ses métiers, son effectif et sa
 * couverture, calculés en une requête.
 */
class OfferDirectory
{
    public const STATUSES = ['soumise', 'en_controle', 'a_corriger', 'publiee', 'suspendue', 'pourvue', 'cloturee', 'annulee'];

    /**
     * Listes « à traiter » : en attente de matching, proposition chez le promoteur, acompte attendu, offre partiellement
     * pourvue, date limite dépassée.
     */
    public const TODO = ['a_matcher' => 'À matcher', 'proposition' => 'Proposition à valider', 'acompte' => 'Acompte attendu', 'incomplet' => 'Partiellement pourvus', 'expires' => 'Date limite dépassée'];

    public const SORTS = ['reference' => 'offers.reference', 'start' => 'offers.start_date', 'deadline' => 'offers.application_deadline', 'coverage' => 'coverage_percent', 'status' => 'offers.status', 'submitted_at' => 'offers.submitted_at'];

    /**
     * @param  array<string, mixed>  $filters  Filtres validés (OfferIndexRequest).
     * @return Builder<Offer>
     */
    public function query(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'submitted_at';
        $direction = ($filters['direction'] ?? ($sort === 'submitted_at' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        return $this->filtered($filters)
            ->select('offers.*')
            ->selectRaw('coalesce(lines.total, 0) as required_workers')
            ->selectRaw('coalesce(lines.trades, 0) as trades_count')
            ->selectRaw('lines.main_trade as main_trade')
            ->selectRaw('coalesce(staff.total, 0) as assigned_workers')
            ->selectRaw('case when coalesce(lines.total, 0) > 0 then least(100, round(coalesce(staff.total, 0) * 100.0 / lines.total)) else 0 end as coverage_percent')
            ->selectRaw('proposals_state.pending as has_pending_proposal')
            ->selectRaw('proposals_state.accepted as has_accepted_proposal')
            ->with(['site.promoterProfile.user', 'site.organization', 'site.commune'])
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' nulls last')
            ->orderBy('offers.id');
    }

    /**
     * @return array{total: int, statuses: array<string, int>, todo: array<string, int>}
     */
    public function counts(): array
    {
        $statuses = Offer::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

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
        return [
            'trades' => Trade::query()->whereIn('id', DB::table('needs')->whereNull('deleted_at')->select('trade_id'))->orderBy('label')->get(['id', 'label'])->map(fn (Trade $trade) => ['id' => $trade->id, 'label' => $trade->label])->all(),
            'communes' => Commune::query()->whereIn('id', DB::table('offers')->join('sites', 'sites.id', '=', 'offers.site_id')->whereNotNull('sites.commune_id')->select('sites.commune_id'))->orderBy('name')->get(['id', 'name'])->map(fn (Commune $commune) => ['id' => $commune->id, 'name' => $commune->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Offer>
     */
    private function filtered(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $open = [OfferStatus::Soumise->value, OfferStatus::EnControle->value, OfferStatus::Publiee->value];

        return $this->base()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('offers.status', $status))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('offers.reference', 'ilike', $search)
                ->orWhere('offers.title', 'ilike', $search)
                ->orWhereHas('site', fn (Builder $query) => $query->where('label', 'ilike', $search)->orWhere('reference', 'ilike', $search))
                ->orWhereHas('site.organization', fn (Builder $query) => $query->where('trade_name', 'ilike', $search)->orWhere('legal_name', 'ilike', $search))
                ->orWhereHas('site.promoterProfile.user', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search]))))
            ->when($filters['trade_id'] ?? null, fn (Builder $query, string $trade) => $query->whereHas('needs', fn (Builder $query) => $query->where('trade_id', $trade)))
            ->when($filters['commune_id'] ?? null, fn (Builder $query, string $commune) => $query->whereHas('site', fn (Builder $query) => $query->where('commune_id', $commune)))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $site) => $query->where('offers.site_id', $site))
            ->when($filters['offer_type'] ?? null, fn (Builder $query, string $type) => $query->where('offers.offer_type', $type))
            ->when($filters['urgency'] ?? null, fn (Builder $query, string $urgency) => $query->where('offers.urgency', $urgency))
            ->when($filters['todo'] ?? null, fn (Builder $query, string $list) => match ($list) {
                'a_matcher' => $query->whereIn('offers.status', $open)->whereRaw('not coalesce(proposals_state.pending, false)')->whereRaw('not coalesce(proposals_state.accepted, false)')->whereRaw('coalesce(staff.total, 0) = 0'),
                'proposition' => $query->whereRaw('coalesce(proposals_state.pending, false)'),
                'acompte' => $query->whereRaw('coalesce(proposals_state.accepted, false)')->whereRaw('coalesce(staff.total, 0) = 0'),
                'incomplet' => $query->whereIn('offers.status', $open)->whereRaw('coalesce(staff.total, 0) > 0')->whereRaw('coalesce(staff.total, 0) < coalesce(lines.total, 0)'),
                'expires' => $query->whereIn('offers.status', $open)->where('offers.application_deadline', '<', now()),
            });
    }

    /**
     * Offres et leurs agrégats.
     *
     * @return Builder<Offer>
     */
    private function base(): Builder
    {
        $lines = DB::table('needs')
            ->join('trades', 'trades.id', '=', 'needs.trade_id')
            ->whereNull('needs.deleted_at')
            ->groupBy('needs.offer_id')
            ->selectRaw('needs.offer_id, sum(needs.headcount) as total, count(*) as trades, (array_agg(trades.label order by needs.headcount desc, trades.label))[1] as main_trade');

        $staff = DB::table('assignments')
            ->join('needs', 'needs.id', '=', 'assignments.need_id')
            ->whereIn('assignments.status', [AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value, AssignmentStatus::Terminee->value])
            ->groupBy('needs.offer_id')
            ->selectRaw('needs.offer_id, count(*) as total');

        $pending = ProposalStatus::AValider->value;
        $accepted = ProposalStatus::Acceptee->value;
        $proposals = DB::table('proposals')
            ->groupBy('offer_id')
            ->selectRaw("offer_id, bool_or(status = '{$pending}') as pending, bool_or(status = '{$accepted}') as accepted");

        return Offer::query()
            ->leftJoinSub($lines, 'lines', 'lines.offer_id', '=', 'offers.id')
            ->leftJoinSub($staff, 'staff', 'staff.offer_id', '=', 'offers.id')
            ->leftJoinSub($proposals, 'proposals_state', 'proposals_state.offer_id', '=', 'offers.id');
    }
}
