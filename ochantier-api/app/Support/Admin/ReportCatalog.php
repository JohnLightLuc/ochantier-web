<?php

namespace App\Support\Admin;

use App\Enums\MissionStatus;
use App\Enums\RatingStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\Mission;
use App\Models\Rating;
use App\Models\Site;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rapports du Back Office : une description commune (indicateurs, colonnes, lignes) que l’écran et les exports
 * partagent. Le rapport financier reprend FinanceReport, pour que ses chiffres concordent avec Finance.
 */
class ReportCatalog
{
    public const KINDS = ['financier' => 'Rapport financier', 'missions' => 'Rapport des missions', 'chantiers' => 'Rapport des chantiers', 'ouvriers' => 'Rapport des ouvriers'];

    private const MONTHS = 12;

    public function __construct(private FinanceReport $finance) {}

    /**
     * Le rapport financier demande aussi le droit de lire Finance.
     */
    public function allowed(string $kind, Admin $admin): bool
    {
        return $kind !== 'financier' || $admin->hasPermission('finance.read');
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Admin $admin): array
    {
        $today = CarbonImmutable::today();
        $validated = Worker::query()->where('verification_status', 'valide')->count();
        $active = $this->activeWorkers($today->subDays(6), $today);
        $average = Rating::query()->where('status', RatingStatus::Publie)->avg('overall_score');

        return [
            'kinds' => collect(self::KINDS)->filter(fn (string $label, string $kind) => $this->allowed($kind, $admin))->map(fn (string $label, string $kind) => ['kind' => $kind, 'label' => $label])->values()->all(),
            'indicators' => [
                'sites_active' => Site::query()->where('status', SiteStatus::EnCours)->count(),
                'sites_done' => Site::query()->where('status', SiteStatus::Termine)->count(),
                'occupation' => $validated ? (int) round($active / $validated * 100) : 0,
                'rating' => $average !== null ? round((float) $average, 1) : null,
            ],
            'months' => $admin->hasPermission('finance.read') ? $this->months($today) : [],
        ];
    }

    /**
     * @return array{kind: string, title: string, period: array{from: string, to: string}, indicators: list<array{label: string, value: int|string, money?: bool}>, columns: list<array{key: string, label: string, money?: bool}>, rows: list<array<string, mixed>>}
     */
    public function build(string $kind, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'kind' => $kind,
            'title' => self::KINDS[$kind],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ...match ($kind) {
                'financier' => $this->financial($from, $to),
                'missions' => $this->missions($from, $to),
                'chantiers' => $this->sites($from, $to),
                default => $this->workers($from, $to),
            },
        ];
    }

    /**
     * @return list<array{month: string, label: string, received: int, paid_out: int, collected: int, revenue: int}>
     */
    private function months(CarbonImmutable $today): array
    {
        return collect(range(self::MONTHS - 1, 0))->map(function (int $back) use ($today): array {
            $month = $today->startOfMonth()->subMonthsNoOverflow($back);
            $report = $this->finance->overview($month, $month->endOfMonth());

            return [
                'month' => $month->format('Y-m'),
                'label' => Str::ucfirst($month->locale('fr')->translatedFormat('M y')),
                'received' => $report['inflows']['total'],
                'paid_out' => $report['outflows']['total'],
                'collected' => $report['commissions']['collected'],
                'revenue' => $report['revenue'],
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function financial(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $total = $this->finance->overview($from, $to);
        $rows = [];

        for ($start = $from; $start->lessThanOrEqualTo($to); $start = $start->addMonthNoOverflow()->startOfMonth()) {
            $end = $start->endOfMonth()->min($to);
            $month = $this->finance->overview($start, $end);
            $rows[] = [
                'period' => $start->format('Y-m'),
                'received' => $month['inflows']['total'],
                'paid_out' => $month['outflows']['total'],
                'net_cash' => $month['net_cash'],
                'earned' => $month['commissions']['earned'],
                'collected' => $month['commissions']['collected'],
                'revenue' => $month['revenue'],
            ];
        }

        return [
            'indicators' => [
                ['label' => 'Reçu', 'value' => $total['inflows']['total'], 'money' => true],
                ['label' => 'Décaissé', 'value' => $total['outflows']['total'], 'money' => true],
                ['label' => 'Commissions acquises', 'value' => $total['commissions']['earned'], 'money' => true],
                ['label' => 'Revenu O’Chantier', 'value' => $total['revenue'], 'money' => true],
            ],
            'columns' => [
                ['key' => 'period', 'label' => 'Mois'],
                ['key' => 'received', 'label' => 'Reçu', 'money' => true],
                ['key' => 'paid_out', 'label' => 'Décaissé', 'money' => true],
                ['key' => 'net_cash', 'label' => 'Solde', 'money' => true],
                ['key' => 'earned', 'label' => 'Commissions acquises', 'money' => true],
                ['key' => 'collected', 'label' => 'Commissions encaissées', 'money' => true],
                ['key' => 'revenue', 'label' => 'Revenu', 'money' => true],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Missions dont les dates recoupent la période ; journées et argent portent sur la période.
     *
     * @return array<string, mixed>
     */
    private function missions(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $missions = Mission::query()
            ->where('planned_start_date', '<=', $to->toDateString())
            ->where('planned_end_date', '>=', $from->toDateString())
            ->with('site')
            ->withCount(['assignments as workers'])
            ->orderBy('planned_start_date')
            ->orderBy('reference')
            ->get();
        $days = $this->validatedDays('mission_id', $from, $to);
        $rows = $missions->map(fn (Mission $mission) => [
            'reference' => $mission->reference,
            'site' => $mission->site?->label,
            'status' => $mission->status->label(),
            'start' => $mission->planned_start_date?->toDateString(),
            'end' => $mission->planned_end_date?->toDateString(),
            'workers' => (int) $mission->workers,
            'days' => (int) ($days[$mission->id]->days ?? 0),
            'amount' => (int) ($days[$mission->id]->gross ?? 0),
        ])->values();

        return [
            'indicators' => [
                ['label' => 'Missions', 'value' => $rows->count()],
                ['label' => 'En cours', 'value' => $missions->where('status', MissionStatus::EnCours)->count()],
                ['label' => 'Terminées', 'value' => $missions->where('status', MissionStatus::Terminee)->count()],
                ['label' => 'Journées validées', 'value' => (int) $rows->sum('days')],
            ],
            'columns' => [
                ['key' => 'reference', 'label' => 'Mission'],
                ['key' => 'site', 'label' => 'Chantier'],
                ['key' => 'status', 'label' => 'Statut'],
                ['key' => 'start', 'label' => 'Début'],
                ['key' => 'end', 'label' => 'Fin'],
                ['key' => 'workers', 'label' => 'Ouvriers'],
                ['key' => 'days', 'label' => 'Journées validées'],
                ['key' => 'amount', 'label' => 'Coût ouvriers', 'money' => true],
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * Chantiers ayant une mission sur la période.
     *
     * @return array<string, mixed>
     */
    private function sites(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sites = Site::query()
            ->whereHas('missions', fn ($query) => $query->where('planned_start_date', '<=', $to->toDateString())->where('planned_end_date', '>=', $from->toDateString()))
            ->withCount(['missions'])
            ->withAvg(['ratings as rating' => fn ($query) => $query->where('status', RatingStatus::Publie)], 'overall_score')
            ->orderBy('label')
            ->get();
        $days = $this->validatedDays('site_id', $from, $to);
        $rows = $sites->map(fn (Site $site) => [
            'reference' => $site->reference,
            'label' => $site->label,
            'status' => $site->status->label(),
            'missions' => (int) $site->missions_count,
            'workers' => (int) ($days[$site->id]->workers ?? 0),
            'days' => (int) ($days[$site->id]->days ?? 0),
            'amount' => (int) ($days[$site->id]->gross ?? 0),
            'rating' => $site->rating !== null ? round((float) $site->rating, 1) : null,
        ])->values();

        return [
            'indicators' => [
                ['label' => 'Chantiers', 'value' => $rows->count()],
                ['label' => 'Journées validées', 'value' => (int) $rows->sum('days')],
                ['label' => 'Coût ouvriers', 'value' => (int) $rows->sum('amount'), 'money' => true],
            ],
            'columns' => [
                ['key' => 'reference', 'label' => 'Référence'],
                ['key' => 'label', 'label' => 'Chantier'],
                ['key' => 'status', 'label' => 'Statut'],
                ['key' => 'missions', 'label' => 'Missions'],
                ['key' => 'workers', 'label' => 'Ouvriers actifs'],
                ['key' => 'days', 'label' => 'Journées validées'],
                ['key' => 'amount', 'label' => 'Coût ouvriers', 'money' => true],
                ['key' => 'rating', 'label' => 'Note'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * Ouvriers ayant au moins une journée validée sur la période.
     *
     * @return array<string, mixed>
     */
    private function workers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->where('d.status', WorkDayStatus::Valide->value)
            ->whereBetween('d.work_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('a.worker_id')
            ->selectRaw('a.worker_id, count(*) as days, coalesce(sum(a.worker_net_daily_rate), 0) as net')
            ->get()
            ->keyBy('worker_id');
        $workers = Worker::query()->whereIn('id', $days->keys())->with(['trades' => fn ($query) => $query->wherePivot('is_primary', true)])->orderBy('last_name')->orderBy('first_name')->get();
        $validated = Worker::query()->where('verification_status', 'valide')->count();
        $rows = $workers->map(fn (Worker $worker) => [
            'reference' => $worker->reference,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'trade' => $worker->trades->first()?->label,
            'status' => $worker->verification_status?->label(),
            'days' => (int) $days[$worker->id]->days,
            'net' => (int) $days[$worker->id]->net,
            'rating' => $worker->rating_avg !== null ? round((float) $worker->rating_avg, 1) : null,
        ])->sortByDesc('days')->values();

        return [
            'indicators' => [
                ['label' => 'Ouvriers actifs', 'value' => $rows->count()],
                ['label' => 'Ouvriers validés', 'value' => $validated],
                ['label' => 'Taux d’occupation', 'value' => ($validated ? (int) round($rows->count() / $validated * 100) : 0).' %'],
                ['label' => 'Journées validées', 'value' => (int) $rows->sum('days')],
            ],
            'columns' => [
                ['key' => 'reference', 'label' => 'Référence'],
                ['key' => 'name', 'label' => 'Ouvrier'],
                ['key' => 'trade', 'label' => 'Métier'],
                ['key' => 'status', 'label' => 'Statut'],
                ['key' => 'days', 'label' => 'Journées validées'],
                ['key' => 'net', 'label' => 'Net gagné', 'money' => true],
                ['key' => 'rating', 'label' => 'Note'],
            ],
            'rows' => $rows->all(),
        ];
    }

    private function activeWorkers(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->where('d.status', WorkDayStatus::Valide->value)
            ->whereBetween('d.work_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->count('a.worker_id');
    }

    /**
     * Journées validées de la période, regroupées par mission ou par chantier.
     *
     * @return Collection<string, object>
     */
    private function validatedDays(string $key, CarbonImmutable $from, CarbonImmutable $to)
    {
        $column = $key === 'site_id' ? 'm.site_id' : 'd.mission_id';

        return DB::table('work_days as d')
            ->join('assignments as a', 'a.id', '=', 'd.assignment_id')
            ->join('missions as m', 'm.id', '=', 'd.mission_id')
            ->where('d.status', WorkDayStatus::Valide->value)
            ->whereBetween('d.work_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy($column)
            ->selectRaw("{$column} as id, count(*) as days, count(distinct a.worker_id) as workers, coalesce(sum(coalesce(a.promoter_daily_rate, a.worker_net_daily_rate, 0)), 0) as gross")
            ->get()
            ->keyBy('id');
    }
}
