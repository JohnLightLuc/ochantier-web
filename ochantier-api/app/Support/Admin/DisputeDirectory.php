<?php

namespace App\Support\Admin;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Illuminate\Database\Eloquent\Builder;

/**
 * Litiges du Back Office : une ligne par dossier, recours compris, les plus urgents en tête.
 */
class DisputeDirectory
{
    public const OPEN = [DisputeStatus::Ouvert, DisputeStatus::EnInstruction, DisputeStatus::EnRecours];

    /**
     * @param  array<string, mixed>  $filters  search, status, category, urgency, unassigned
     * @return Builder<Dispute>
     */
    public function disputes(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $open = implode("','", array_map(fn (DisputeStatus $status) => $status->value, self::OPEN));

        return Dispute::query()
            ->with(['site', 'mission', 'assignedAdmin', 'decidedByAdmin', 'appealOfDispute', 'parties.party'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['urgency'] ?? null, fn (Builder $query, string $urgency) => $query->where('urgency', $urgency))
            ->when($filters['unassigned'] ?? false, fn (Builder $query) => $query->whereNull('assigned_admin_id')->whereIn('status', self::OPEN))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhere('description', 'ilike', $search)
                ->orWhereHas('site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->orderByRaw("case when status in ('{$open}') then 0 else 1 end")
            ->orderByRaw("case urgency when 'critique' then 0 when 'haute' then 1 when 'normale' then 2 else 3 end")
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return array{statuses: array<string, int>, unassigned: int, refunds_pending: int}
     */
    public function counts(): array
    {
        $statuses = Dispute::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'statuses' => collect(DisputeStatus::cases())->mapWithKeys(fn (DisputeStatus $status) => [$status->value => (int) $statuses->get($status->value, 0)])->all(),
            'unassigned' => Dispute::query()->whereNull('assigned_admin_id')->whereIn('status', self::OPEN)->count(),
            'refunds_pending' => Dispute::query()->whereRaw("(financial_impact->>'refund_pending')::boolean is true")->count(),
        ];
    }
}
