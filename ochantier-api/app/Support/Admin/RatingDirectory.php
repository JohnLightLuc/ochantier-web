<?php

namespace App\Support\Admin;

use App\Enums\RatingModerationStatus;
use App\Enums\RatingStatus;
use App\Models\Rating;
use Illuminate\Database\Eloquent\Builder;

/**
 * Avis du Back Office : une ligne par avis publié ou masqué.
 */
class RatingDirectory
{
    public const VISIBLE = [RatingStatus::Publie, RatingStatus::Masque];

    /**
     * @param  array<string, mixed>  $filters  search, status, moderation, scope, score, suspected
     * @return Builder<Rating>
     */
    public function ratings(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $worker = "concat_ws(' ', workers.first_name, workers.last_name)";

        return Rating::query()
            ->whereIn('ratings.status', self::VISIBLE)
            ->leftJoin('sites', 'sites.id', '=', 'ratings.site_id')
            ->leftJoin('promoter_profiles', 'promoter_profiles.id', '=', 'sites.promoter_profile_id')
            ->leftJoin('users', 'users.id', '=', 'promoter_profiles.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'sites.organization_id')
            ->leftJoin('workers', 'workers.id', '=', 'ratings.worker_id')
            ->select('ratings.*')
            ->selectRaw($this->promoterName().' as promoter_name')
            ->selectRaw("nullif({$worker}, '') as worker_name")
            ->with(['site', 'authorUser', 'moderatedByAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('ratings.status', $status))
            ->when($filters['moderation'] ?? null, fn (Builder $query, string $status) => $query->where('ratings.moderation_status', $status))
            ->when($filters['scope'] ?? null, fn (Builder $query, string $scope) => $query->where('ratings.rating_scope', $scope))
            ->when($filters['score'] ?? null, fn (Builder $query, int|string $score) => $query->where('ratings.overall_score', (int) $score))
            ->when($filters['suspected'] ?? false, fn (Builder $query) => $query->where('ratings.fraud_suspected', true))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('sites.label', 'ilike', $search)
                ->orWhere('ratings.comment', 'ilike', $search)
                ->orWhereRaw("{$worker} ilike ?", [$search])
                ->orWhereRaw($this->promoterName().' ilike ?', [$search])))
            ->orderByRaw("case when ratings.moderation_status = 'signale' then 0 else 1 end")
            ->orderByDesc('ratings.published_at')
            ->orderBy('ratings.id');
    }

    /**
     * @return array{published: int, hidden: int, reported: int, suspected: int, average: float|null}
     */
    public function counts(): array
    {
        $published = Rating::query()->where('status', RatingStatus::Publie);

        return [
            'published' => (clone $published)->count(),
            'hidden' => Rating::query()->where('status', RatingStatus::Masque)->count(),
            'reported' => Rating::query()->whereIn('status', self::VISIBLE)->where('moderation_status', RatingModerationStatus::Signale)->count(),
            'suspected' => Rating::query()->whereIn('status', self::VISIBLE)->where('fraud_suspected', true)->count(),
            'average' => ($average = (clone $published)->avg('overall_score')) !== null ? round((float) $average, 1) : null,
        ];
    }

    private function promoterName(): string
    {
        return "coalesce(nullif(organizations.trade_name, ''), organizations.legal_name, nullif(concat_ws(' ', users.first_name, users.last_name), ''))";
    }
}
