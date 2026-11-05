<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Models\CommercialRanking;
use App\Models\CommercialRankingEntry;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Commercial\CommercialPerformance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Classement mensuel des commerciaux. Calculé en direct sur les ouvriers validés tant que le mois n’est pas clôturé ;
 * la clôture fige les rangs, sans retour possible, et la publication les montre aux commerciaux.
 */
class CommercialRankings
{
    public function __construct(private CommercialPerformance $performance, private CommercialNotifier $notifier) {}

    /**
     * @return array<string, mixed>
     */
    public function month(CarbonImmutable $month): array
    {
        $ranking = $this->find($month);
        $rows = $ranking?->closed_at ? $this->frozen($ranking) : $this->live($month);
        $finished = $month->endOfMonth()->lt(today());

        return [
            'month' => $month->format('Y-m'),
            'label' => mb_convert_case($month->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE),
            'closed_at' => $ranking?->closed_at?->toIso8601String(),
            'published_at' => $ranking?->published_at?->toIso8601String(),
            'can_close' => $finished && ! $ranking?->closed_at && $rows->isNotEmpty(),
            'can_publish' => $ranking?->closed_at !== null && $ranking->published_at === null,
            'top' => $ranking?->closed_at && ($rows->first()['validated'] ?? 0) > 0 ? $rows->first() : null,
            'rows' => $rows->values()->all(),
            'months' => collect(range(0, 11))->map(function (int $offset) {
                $item = CarbonImmutable::today()->startOfMonth()->subMonths($offset);

                return ['value' => $item->format('Y-m'), 'label' => mb_convert_case($item->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE)];
            })->all(),
        ];
    }

    public function close(CarbonImmutable $month, Admin $admin, Request $request): void
    {
        if ($month->endOfMonth()->gte(today())) {
            throw ValidationException::withMessages(['ranking' => 'Ce mois n’est pas terminé.']);
        }

        if ($this->find($month)?->closed_at) {
            throw ValidationException::withMessages(['ranking' => 'Ce classement est déjà clôturé.']);
        }

        $rows = $this->live($month);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['ranking' => 'Aucun commercial à classer.']);
        }

        DB::transaction(function () use ($month, $rows, $admin): void {
            $ranking = CommercialRanking::query()->firstOrCreate(['period_month' => $month->toDateString()], ['criteria' => ['basis' => 'ouvriers_valides'], 'created_by_admin_id' => $admin->getKey()]);
            $ranking->update(['closed_at' => now()]);

            foreach ($rows as $row) {
                CommercialRankingEntry::query()->create(['ranking_id' => $ranking->getKey(), 'commercial_id' => $row['commercial_id'], 'rank' => $row['rank'], 'score' => $row['validated'], 'metrics' => ['validated' => $row['validated']]]);
            }
        });

        AdminAudit::record($admin, 'commercials', 'commercial.ranking_closed', $request, $this->find($month), null, ['month' => $month->format('Y-m')]);
    }

    public function publish(CarbonImmutable $month, Admin $admin, Request $request): void
    {
        $ranking = $this->find($month);

        if (! $ranking?->closed_at) {
            throw ValidationException::withMessages(['ranking' => 'Clôturez d’abord ce classement.']);
        }

        if ($ranking->published_at) {
            throw ValidationException::withMessages(['ranking' => 'Ce classement est déjà publié.']);
        }

        $ranking->update(['published_at' => now()]);
        AdminAudit::record($admin, 'commercials', 'commercial.ranking_published', $request, $ranking, null, ['month' => $month->format('Y-m')]);

        $label = $month->locale('fr')->translatedFormat('F Y');

        foreach ($ranking->entries()->with('commercial.user')->get() as $entry) {
            $this->notifier->rankingPublished($entry->commercial, "Classement de {$label} : vous êtes ".$this->performance->ordinal($entry->rank).'.', $ranking->id);
        }
    }

    private function find(CarbonImmutable $month): ?CommercialRanking
    {
        return CommercialRanking::query()->whereDate('period_month', $month->toDateString())->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function live(CarbonImmutable $month): Collection
    {
        $profiles = CommercialProfile::query()->get(['id', 'employee_number'])->keyBy('id');

        return $this->performance->board($month)->map(fn (array $row) => [...$row, 'employee_number' => $profiles[$row['commercial_id']]->employee_number]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function frozen(CommercialRanking $ranking): Collection
    {
        return $ranking->entries()->with('commercial.user')->orderBy('rank')->get()->map(fn (CommercialRankingEntry $entry) => [
            'commercial_id' => $entry->commercial_id,
            'name' => trim($entry->commercial->user->first_name.' '.$entry->commercial->user->last_name),
            'employee_number' => $entry->commercial->employee_number,
            'validated' => (int) ($entry->metrics['validated'] ?? 0),
            'rank' => $entry->rank,
        ]);
    }
}
