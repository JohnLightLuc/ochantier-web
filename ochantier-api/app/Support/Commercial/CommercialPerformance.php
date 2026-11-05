<?php

namespace App\Support\Commercial;

use App\Enums\CommercialObjectiveIndicator;
use App\Enums\CommercialProfileStatus;
use App\Enums\ProspectStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialObjective;
use App\Models\CommercialProfile;
use App\Models\CommercialRankingEntry;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Activité du commercial : indicateurs de la semaine, objectif du mois (fixé par O’CHANTIER) et classement parmi les
 * commerciaux actifs, calculé en direct sur les ouvriers validés du mois. Les ouvriers enregistrés par le Back Office
 * ne comptent pas dans ces résultats.
 */
class CommercialPerformance
{
    /**
     * Statuts d’un dossier transmis au Back office et encore en traitement.
     */
    public const IN_REVIEW = [WorkerVerificationStatus::Soumis, WorkerVerificationStatus::EnControle];

    /**
     * « Mon activité · Cette semaine » : prospects ajoutés, dossiers soumis, ouvriers validés, et dossiers à corriger.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function week(CommercialProfile $commercial): array
    {
        $start = CarbonImmutable::today()->startOfWeek();
        $workers = Worker::query()->where('origin_commercial_id', $commercial->getKey())->whereRaw(Worker::RECRUITED_SQL);

        return [
            ['key' => 'prospects', 'label' => 'Prospects', 'count' => $commercial->prospects()->where('created_at', '>=', $start)->where('status', '!=', ProspectStatus::Abandonne)->count()],
            ['key' => 'soumis', 'label' => 'Ouvriers soumis', 'count' => (clone $workers)->where('submitted_at', '>=', $start)->count()],
            ['key' => 'valides', 'label' => 'Validés', 'count' => (clone $workers)->where('verification_status', WorkerVerificationStatus::Valide)->where('validated_at', '>=', $start)->count()],
            ['key' => 'a_corriger', 'label' => 'À corriger', 'count' => (clone $workers)->where('verification_status', WorkerVerificationStatus::ACorriger)->count()],
        ];
    }

    /**
     * Objectif du mois en ouvriers validés, ou null s’il n’a pas été fixé.
     *
     * @return array<string, mixed>|null
     */
    public function objective(CommercialProfile $commercial, ?CarbonImmutable $month = null): ?array
    {
        $month = ($month ?? CarbonImmutable::today())->startOfMonth();
        $objective = $commercial->commercialObjectives()
            ->where('indicator', CommercialObjectiveIndicator::OuvriersValides)
            ->whereDate('period_start', '<=', $month->endOfMonth())
            ->whereDate('period_end', '>=', $month)
            ->latest('period_start')
            ->first();

        if (! $objective) {
            return null;
        }

        $achieved = $this->validated($commercial, CarbonImmutable::parse($objective->period_start), CarbonImmutable::parse($objective->period_end)->endOfDay());
        $rest = max(0, $objective->target_value - $achieved);

        return [
            'indicator' => ['value' => CommercialObjectiveIndicator::OuvriersValides->value, 'label' => 'Ouvriers validés'],
            'period' => ['start' => $objective->period_start->toDateString(), 'end' => $objective->period_end->toDateString()],
            'period_label' => mb_convert_case($month->locale('fr')->translatedFormat('M Y'), MB_CASE_TITLE),
            'target' => $objective->target_value,
            'achieved' => $achieved,
            'label' => $achieved.' / '.$objective->target_value,
            'percent' => min(100, (int) round($achieved / $objective->target_value * 100)),
            'remaining' => $rest,
            'message' => $rest > 0 ? 'Plus que '.$rest.' ouvrier'.($rest > 1 ? 's' : '').' validé'.($rest > 1 ? 's' : '').' pour atteindre votre objectif.' : 'Objectif atteint, bravo !',
            'comment' => $objective->comment,
        ];
    }

    /**
     * Objectifs des 6 derniers mois, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function objectiveHistory(CommercialProfile $commercial): array
    {
        return $commercial->commercialObjectives()
            ->where('indicator', CommercialObjectiveIndicator::OuvriersValides)
            ->latest('period_start')
            ->limit(6)
            ->get()
            ->map(fn (CommercialObjective $objective) => $this->objective($commercial, CarbonImmutable::parse($objective->period_start)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Classement du mois en cours (calcul en direct) et derniers classements publiés par le Back office.
     *
     * @return array<string, mixed>
     */
    public function ranking(CommercialProfile $commercial): array
    {
        $board = $this->board(CarbonImmutable::today());
        $mine = $board->firstWhere('commercial_id', $commercial->getKey());
        $position = $mine['rank'] ?? null;

        return [
            'period_label' => mb_convert_case(CarbonImmutable::today()->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE),
            'rank' => $position,
            'rank_label' => $position ? $this->ordinal($position) : null,
            'total' => $board->count(),
            'label' => $position ? $this->ordinal($position).' sur '.$board->count().' commerciaux' : null,
            'validated' => $mine['validated'] ?? 0,
            'leaders' => $board->take(5)->map(fn (array $row) => [
                'rank' => $row['rank'],
                'name' => $row['name'],
                'validated' => $row['validated'],
                'is_me' => $row['commercial_id'] === $commercial->getKey(),
            ])->values()->all(),
            'published' => CommercialRankingEntry::query()
                ->where('commercial_id', $commercial->getKey())
                ->whereHas('ranking', fn ($query) => $query->whereNotNull('published_at'))
                ->with('ranking')
                ->get()
                ->sortByDesc(fn (CommercialRankingEntry $entry) => $entry->ranking->period_month)
                ->take(6)
                ->map(fn (CommercialRankingEntry $entry) => [
                    'month' => CarbonImmutable::parse($entry->ranking->period_month)->format('Y-m'),
                    'rank' => $entry->rank,
                    'rank_label' => $this->ordinal($entry->rank),
                    'score' => (float) $entry->score,
                ])
                ->values()
                ->all(),
            'note' => 'Classé sur les ouvriers validés du mois ; à égalité, par ordre alphabétique.',
        ];
    }

    /**
     * Ouvriers du commercial validés sur la période.
     */
    public function validated(CommercialProfile $commercial, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Worker::query()
            ->where('origin_commercial_id', $commercial->getKey())
            ->whereRaw(Worker::RECRUITED_SQL)
            ->where('verification_status', WorkerVerificationStatus::Valide)
            ->whereBetween('validated_at', [$from, $to])
            ->count();
    }

    public function ordinal(int $rank): string
    {
        return $rank === 1 ? '1ᵉʳ' : $rank.'ᵉ';
    }

    /**
     * Commerciaux actifs classés par ouvriers validés dans le mois, puis par nom.
     *
     * @return Collection<int, array{commercial_id: string, name: string, validated: int, rank: int}>
     */
    private function board(CarbonImmutable $month): Collection
    {
        $counts = Worker::query()
            ->where('verification_status', WorkerVerificationStatus::Valide)
            ->whereBetween('validated_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->whereNotNull('origin_commercial_id')
            ->whereRaw(Worker::RECRUITED_SQL)
            ->groupBy('origin_commercial_id')
            ->selectRaw('origin_commercial_id, count(*) as validated')
            ->pluck('validated', 'origin_commercial_id');

        return CommercialProfile::query()
            ->where('status', CommercialProfileStatus::Actif)
            ->with('user')
            ->get()
            ->map(fn (CommercialProfile $profile) => [
                'commercial_id' => $profile->getKey(),
                'name' => trim($profile->user->first_name.' '.$profile->user->last_name),
                'validated' => (int) ($counts[$profile->getKey()] ?? 0),
            ])
            ->sortBy([['validated', 'desc'], ['name', 'asc']])
            ->values()
            ->map(fn (array $row, int $index) => [...$row, 'rank' => $index + 1]);
    }
}
