<?php

namespace App\Support;

use App\Enums\SiteStatus;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsSchedule;
use App\Models\Attendance;
use App\Models\Review;
use App\Models\Site;
use App\Models\Worker;
use App\Support\PageBlocks as UI;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rapports & analyses : données de la plateforme (opérations du registre financier, chantiers, ouvriers, pointages,
 * avis) filtrées par chantier, zone et famille de métiers, regroupées selon le rythme choisi (mensuel, trimestriel,
 * annuel), et rapports prêts à afficher ou à exporter (financier, missions, chantiers, ouvriers, bibliothèque).
 */
class AnalyticsInsights
{
    /**
     * Rapports : clé → libellé, icône, description.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const KINDS = [
        'financier' => ['Rapport financier', 'bar_chart', 'Encaissements, reversements et commissions de la plateforme'],
        'missions' => ['Répartition des missions', 'pie_chart', 'Missions par métier et par commune'],
        'chantiers' => ['Performance des chantiers', 'domain', 'Avancement, coûts, délais et satisfaction par chantier'],
        'ouvriers' => ['Indicateurs des ouvriers', 'group', 'Inscriptions, disponibilités, présence et activité'],
        'bibliotheque' => ['Rapports générés', 'description', 'Rapports récents, générations et programmations'],
    ];

    /**
     * Familles de métiers : libellé, motif, couleur.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const FAMILIES = [
        ['Maçonnerie', '/Maçon|Ferrail|Coffr|Manœuvre|Terrass|Chef/iu', 'var(--c-primary)'], ['Électricité', '/Électri/iu', 'var(--c-accent-600)'],
        ['Plomberie', '/Plomb|Climat/iu', 'var(--c-info)'], ['Peinture', '/Peint|Plâtr/iu', 'var(--c-purple)'], ['Autres', '/./u', 'var(--c-neutral)'],
    ];

    public const RHYTHMS = ['Mensuel', 'Trimestriel', 'Annuel'];

    public const MONTHS = ['Jan.', 'Fév.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    public string $rhythm;

    /** @var array<string, string> */
    public array $filters;

    /** @var Collection<int, Site> Chantiers suivis (hors brouillons, rattachés à un promoteur). */
    public Collection $sites;

    /** @var Collection<int, array<string, mixed>> Opérations éligibles du registre financier. */
    public Collection $entries;

    /** @var Collection<int, Worker> */
    public Collection $workers;

    /** @var Collection<int, Attendance> */
    public Collection $attendances;

    /** @var Collection<int, Review> Avis comptés dans la note moyenne. */
    public Collection $reviews;

    public string $year;

    /**
     * @param  array<string, mixed>  $query
     */
    public function __construct(public array $query = [])
    {
        $this->rhythm = in_array($query['rythme'] ?? '', self::RHYTHMS, true) ? $query['rythme'] : 'Mensuel';
        $this->filters = array_filter(array_intersect_key(array_map(fn ($v): string => (string) $v, $query), array_flip(['chantier', 'zone', 'metier'])), fn (string $v): bool => $v !== '');
        $f = $this->filters;
        $this->year = self::today()->format('Y');
        $this->sites = Site::with('promoter')->where('status', '!=', SiteStatus::Draft)->whereNotNull('promoter_name')->orderBy('name')->get()
            ->filter(fn (Site $s): bool => (! isset($f['chantier']) || $s->slug === $f['chantier']) && (! isset($f['zone']) || $s->commune === $f['zone']))->values();
        $this->entries = (new FinanceLedger)->entries()->filter(fn (array $x): bool => $x['elig'] && (! isset($f['chantier']) || $x['siteSlug'] === $f['chantier'])
            && (! isset($f['zone']) || $x['commune'] === $f['zone']) && (! isset($f['metier']) || ($x['workerSlug'] && self::family($x['trade']) === $f['metier'])))->values();
        $active = $this->entries->pluck('workerSlug')->filter()->flip();
        $this->workers = Worker::query()->orderBy('id')->get()->filter(fn (Worker $w): bool => (! isset($f['zone']) || $w->commune === $f['zone'])
            && (! isset($f['metier']) || self::family($w->trade) === $f['metier']) && (! isset($f['chantier']) || isset($active[$w->slug])))->values();
        $ids = $this->workers->pluck('id')->flip();
        $siteIds = $this->sites->pluck('id')->flip();
        $this->attendances = Attendance::query()->whereDate('date', '<=', self::today())->get()->filter(fn (Attendance $a): bool => isset($ids[$a->worker_id]) && isset($siteIds[$a->site_id]))->values();
        $this->reviews = Review::query()->get()->filter(fn (Review $r): bool => $r->isCounted() && isset($siteIds[$r->site_id]) && (! isset($f['metier']) || self::family($r->trade) === $f['metier']))->values();
    }

    public static function today(): CarbonImmutable
    {
        return FinanceLedger::today();
    }

    public static function family(?string $trade): string
    {
        foreach (self::FAMILIES as [$label, $pattern]) {
            if (preg_match($pattern, (string) $trade)) {
                return $label;
            }
        }

        return 'Autres';
    }

    public static function money(int|float $v): string
    {
        return number_format(round($v), 0, ',', ' ').' FCFA';
    }

    public static function number(int|float $v): string
    {
        return number_format(round($v), 0, ',', ' ');
    }

    public static function percent(int|float $v, int|float $total): int
    {
        return $total ? (int) round($v / $total * 100) : 0;
    }

    /**
     * Variation en % (flèche et ton).
     *
     * @return array{text: string, up: bool}
     */
    public static function delta(int|float $value, int|float $previous): array
    {
        $d = $previous ? (int) round(($value - $previous) / $previous * 100) : 0;

        return ['text' => ($d >= 0 ? '▲ +' : '▼ −').abs($d).' %', 'up' => $d >= 0];
    }

    public function filterSummary(): string
    {
        $f = $this->filters;

        return implode(' · ', array_filter([isset($f['chantier']) ? $this->sites->first()?->name : null, $f['zone'] ?? null, $f['metier'] ?? null])) ?: 'Aucun filtre';
    }

    /**
     * Mois de rattachement d’une opération : encaissement, reversement ou prestation.
     *
     * @param  array<string, mixed>  $x
     */
    private static function month(array $x, string $kind): string
    {
        $d = match ($kind) {
            'enc' => $x['d']['enc'] ?? $x['d']['pay'],
            'rev' => $x['d']['rev'] ?? $x['d']['pay'],
            default => $x['d']['presta'] ?? $x['d']['op'],
        };

        return substr((string) $d, 0, 7);
    }

    /**
     * Série financière selon le rythme : encaissements, reversements, commission nette, opérations, en attente.
     *
     * @param  Collection<int, array<string, mixed>>|null  $entries
     * @return array{keys: array<int, string>, labels: array<int, string>, collected: array<int, int>, paidOut: array<int, int>, net: array<int, int>, count: array<int, int>, pending: array<int, int>}
     */
    public function series(?Collection $entries = null): array
    {
        $months = FinanceLedger::months();
        $quarter = fn (string $m): string => substr($m, 0, 4).'-T'.(int) ceil((int) substr($m, 5, 2) / 3);
        [$keys, $labels, $bucket] = match ($this->rhythm) {
            'Trimestriel' => (function () use ($months, $quarter): array {
                $keys = array_values(array_unique(array_map($quarter, array_filter($months, fn (string $m): bool => str_starts_with($m, $this->year)))));

                return [$keys, array_map(fn (string $k): string => substr($k, 5).' '.substr($k, 0, 4), $keys), $quarter];
            })(),
            'Annuel' => (function () use ($months): array {
                $keys = array_values(array_unique(array_map(fn (string $m): string => substr($m, 0, 4), $months)));

                return [$keys, $keys, fn (string $m): string => substr($m, 0, 4)];
            })(),
            default => (function () use ($months): array {
                $keys = array_values(array_filter($months, fn (string $m): bool => str_starts_with($m, $this->year)));

                return [$keys, array_map(fn (string $m): string => self::MONTHS[(int) substr($m, 5, 2) - 1], $keys), fn (string $m): string => $m];
            })(),
        };
        $totals = ['collected' => [], 'paidOut' => [], 'net' => [], 'count' => [], 'pending' => []];
        $add = function (string $series, string $key, int $v) use (&$totals): void {
            $totals[$series][$key] = ($totals[$series][$key] ?? 0) + $v;
        };
        foreach ($entries ?? $this->entries as $x) {
            if ($x['paid']) {
                $k = $bucket(self::month($x, 'enc'));
                $add('collected', $k, $x['received']);
                $add('net', $k, $x['comNet']);
                $add('count', $k, 1);
            } else {
                $add('pending', $bucket(self::month($x, 'op')), $x['promoterTotal']);
            }
            if ($x['revSt'] === 'Reversé') {
                $add('paidOut', $bucket(self::month($x, 'rev')), $x['net']);
            }
        }

        return ['keys' => $keys, 'labels' => $labels, ...array_map(fn (array $values): array => array_map(fn (string $k): int => $values[$k] ?? 0, $keys), $totals)];
    }

    /**
     * Courbes encaissements / reversements avec le détail de chaque période en info-bulle.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function financeLines(string $title, array $s, string $noteLabel, string $note): array
    {
        $money = fn (array $values): array => array_map(fn (int $v): string => self::money($v), $values);

        return [
            'title' => $title, 'sub' => 'Encaissements et reversements · FCFA', 'labels' => $s['labels'], 'note' => ['label' => $noteLabel, 'value' => $note],
            'series' => [
                ['label' => 'Encaissements', 'color' => 'var(--c-primary-500)', 'values' => $s['collected'], 'texts' => $money($s['collected'])],
                ['label' => 'Reversements', 'color' => 'var(--c-accent-600)', 'values' => $s['paidOut'], 'texts' => $money($s['paidOut'])],
            ],
            'extra' => [
                ['label' => 'Commission nette', 'color' => 'var(--c-info)', 'texts' => $money($s['net'])],
                ['label' => 'Opérations encaissées', 'color' => 'var(--c-neutral)', 'texts' => array_map(fn (int $v): string => self::number($v), $s['count'])],
                ['label' => 'En attente d’encaissement', 'color' => 'var(--c-danger)', 'texts' => array_map(fn (int $v): string => $v ? self::money($v) : '', $s['pending'])],
            ],
        ];
    }

    /**
     * Répartition des missions par famille de métiers (anneau).
     *
     * @return array<string, mixed>
     */
    public function split(): array
    {
        $missions = $this->entries->filter(fn (array $x): bool => (bool) $x['workerSlug']);
        $counts = $missions->countBy(fn (array $x): string => self::family($x['trade']));
        $total = $missions->count();

        return [
            'title' => 'Répartition des missions', 'sub' => 'Par famille de métiers', 'total' => self::number($total), 'totalLabel' => 'missions',
            'segments' => array_map(fn (array $f): array => ['label' => $f[0], 'value' => $counts[$f[0]] ?? 0, 'color' => $f[2], 'text' => self::number($counts[$f[0]] ?? 0), 'percent' => self::percent($counts[$f[0]] ?? 0, $total).' %'], self::FAMILIES),
        ];
    }

    /**
     * Performance d’un chantier : écart de coût main-d’œuvre (prévu vs payé), respect des délais, satisfaction.
     *
     * @param  Collection<int, array<string, mixed>>|null  $entries
     * @return array{cost: int, costText: string, up: bool, delay: int, satisfaction: ?float, satisfactionText: string, reviews: int}
     */
    public function performance(Site $site, ?Collection $entries = null): array
    {
        $executed = $site->executedDays();
        $elapsed = $site->planned_days ? min(100, $executed / $site->planned_days * 100) : 0;
        $spent = ($entries ?? $this->entries)->filter(fn (array $x): bool => $x['siteSlug'] === $site->slug && $x['workerSlug'])->sum('gross');
        $planned = max(1, (int) $site->required_workers) * 12000 * $executed;
        $cost = $planned && $spent ? (int) max(-99, min(99, round(($planned - $spent) / $planned * 100))) : 0;
        $reviews = Review::query()->where('site_id', $site->id)->get()->filter(fn (Review $r): bool => $r->isCounted());
        $satisfaction = $reviews->isNotEmpty() ? $reviews->avg(fn (Review $r): ?float => $r->score()) : null;

        return [
            'cost' => $cost, 'costText' => ($cost >= 0 ? '+' : '−').abs($cost).' %', 'up' => $cost >= 0, 'delay' => $elapsed ? (int) min(100, round($site->progress / $elapsed * 100)) : 100,
            'satisfaction' => $satisfaction, 'satisfactionText' => $satisfaction === null ? '—' : number_format($satisfaction, 1, ',', '').' / 5', 'reviews' => $reviews->count(),
        ];
    }

    private function absent(Attendance $a): bool
    {
        return ! empty($a->absence);
    }

    /**
     * Indicateurs des ouvriers : inscrits, validés, disponibles, présence, évolution mensuelle et journalière.
     *
     * @return array<string, mixed>
     */
    public function workerIndicators(): array
    {
        $validated = $this->workers->filter(fn (Worker $w): bool => $w->status === WorkerStatus::Validated);
        $available = $validated->filter(fn (Worker $w): bool => $w->availability === WorkerAvailability::Available);
        $presence = $this->attendances->isNotEmpty() ? $this->attendances->reject(fn (Attendance $a): bool => $this->absent($a))->count() / $this->attendances->count() : null;
        $months = array_slice(FinanceLedger::months(), -9);
        $seen = [];
        $cumulative = [];
        $active = [];
        foreach ($months as $m) {
            $workers = $this->entries->filter(fn (array $x): bool => $x['workerSlug'] && self::month($x, 'op') === $m)->pluck('workerSlug')->unique();
            foreach ($workers as $w) {
                $seen[$w] = true;
            }
            $cumulative[] = count($seen);
            $active[] = $workers->count();
        }
        $byDay = $this->attendances->groupBy(fn (Attendance $a): string => $a->date->format('Y-m-d'))->sortKeys();
        $daily = $byDay->map(fn (Collection $g): float => round($g->reject(fn (Attendance $a): bool => $this->absent($a))->count() / $g->count() * 100, 1));

        return [
            'validated' => $validated, 'available' => $available, 'presence' => $presence, 'months' => array_map(fn (string $m): string => self::MONTHS[(int) substr($m, 5, 2) - 1].' '.substr($m, 0, 4), $months),
            'cumulative' => $cumulative, 'active' => $active, 'days' => $byDay->keys()->map(fn (string $d): string => CarbonImmutable::parse($d)->format('d/m'))->all(), 'daily' => $daily->values()->all(),
        ];
    }

    /**
     * Ligne des tableaux : cellules et lien éventuel.
     *
     * @param  array<int, array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private static function row(string $key, array $cells, ?string $href = null): array
    {
        return UI::row($key, $cells, $href ? UI::link($href) : null, $href ? [['visibility', 'Ouvrir', UI::link($href)]] : []);
    }

    /**
     * Page d’un rapport : indicateurs, graphiques et tableaux (les tableaux marqués « export » alimentent les fichiers).
     *
     * @return array{kpis: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>, donuts: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, tiles: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>}
     */
    public function page(string $kind, ?Site $site = null): array
    {
        $page = ['kpis' => [], 'lines' => [], 'donuts' => [], 'charts' => [], 'tiles' => [], 'tables' => []];
        $kpi = fn (string $label, string $value, string $icon, string $tone, string $hint): array => ['label' => $label, 'value' => $value, 'icon' => $icon, 'tone' => $tone, 'hint' => $hint, 'action' => null];
        $missions = $this->entries->filter(fn (array $x): bool => (bool) $x['workerSlug']);

        if ($kind === 'financier') {
            $s = $this->series();
            $range = $s['labels'] ? $s['labels'][0].(count($s['labels']) > 1 ? ' – '.last($s['labels']) : '') : '—';
            $pending = $this->entries->where('paid', false)->sum('promoterTotal');
            $page['kpis'] = [
                $kpi('Encaissements', self::money(array_sum($s['collected'])), 'payments', 'g', $range), $kpi('Reversements aux ouvriers', self::money(array_sum($s['paidOut'])), 'north_east', 'a', $range),
                $kpi('Commission nette', self::money(array_sum($s['net'])), 'account_balance', 'b', $range), $kpi('Créances en attente', self::money($pending), 'hourglass_top', $pending ? 'r' : 'g', 'Opérations non encaissées'),
            ];
            $page['lines'] = [self::financeLines('Évolution financière', $s, 'Commission nette', self::money(array_sum($s['net'])))];
            $page['tables'][] = UI::table('calendar_month', 'Détail par période', count($s['keys']).' période(s) · '.mb_strtolower($this->rhythm), ['Période', 'Opérations encaissées', 'Encaissements', 'Reversements', 'Commission nette', 'Évolution'], array_map(function (string $k, int $i) use ($s): array {
                $d = $i ? self::delta($s['collected'][$i], $s['collected'][$i - 1]) : null;

                return self::row($k, [UI::text($s['labels'][$i].($this->rhythm === 'Mensuel' ? ' '.substr($k, 0, 4) : ''), true), UI::text(self::number($s['count'][$i])), UI::text(self::money($s['collected'][$i])), UI::text(self::money($s['paidOut'][$i])), UI::text(self::money($s['net'][$i]), true), $d ? UI::badge($d['text'], $d['up'] ? 'g' : 'r') : UI::text('—', false, true)]);
            }, $s['keys'], array_keys($s['keys'])), 900);
            $byPromoter = $this->entries->where('paid', true)->groupBy('promoter')->map(fn (Collection $g, string $p): array => ['name' => $p, 'collected' => (int) $g->sum('received'), 'net' => (int) $g->sum('comNet'), 'sites' => $g->pluck('siteSlug')->unique()->count(), 'count' => $g->count(), 'slug' => $g->first()['promoterSlug']])->sortByDesc('collected')->values();
            $total = $byPromoter->sum('collected');
            $page['tables'][] = UI::table('apartment', 'Encaissements par promoteur', $byPromoter->count().' promoteur(s) · toutes périodes', ['Promoteur', 'Chantiers', 'Opérations', 'Encaissé', 'Commission nette', 'Part des encaissements'], $byPromoter->map(fn (array $p): array => self::row($p['name'], [
                UI::text($p['name'], true), UI::text((string) $p['sites']), UI::text(self::number($p['count'])), UI::text(self::money($p['collected'])), UI::text(self::money($p['net'])), UI::progress(self::percent($p['collected'], $total)),
            ], $p['slug'] ? "/promoteurs/{$p['slug']}" : null))->all(), 1000);
        }

        if ($kind === 'missions') {
            $gross = $missions->sum('gross');
            $page['kpis'] = [
                $kpi('Missions réalisées', self::number($missions->count()), 'assignment_turned_in', 'b', 'Toutes périodes'), $kpi('Ouvriers mobilisés', self::number($missions->pluck('workerSlug')->unique()->count()), 'engineering', 'g', 'Profils distincts'),
                $kpi('Jours facturés', self::number($missions->sum('days')), 'event_available', 'a', 'Jours de prestation'), $kpi('Montant brut', self::money($gross), 'paid', 'a', 'Avant commissions'),
            ];
            $page['donuts'] = [$this->split()];
            $total = $missions->count();
            $page['tables'][] = UI::table('category', 'Synthèse par famille de métiers', count(self::FAMILIES).' familles', ['Famille', 'Missions', 'Ouvriers', 'Montant brut', 'Part'], array_map(function (array $f) use ($missions, $total): array {
                $g = $missions->filter(fn (array $x): bool => self::family($x['trade']) === $f[0]);

                return self::row($f[0], [UI::text($f[0], true), UI::text(self::number($g->count())), UI::text((string) $g->pluck('workerSlug')->unique()->count()), UI::text(self::money($g->sum('gross'))), UI::progress(self::percent($g->count(), $total))], $g->isNotEmpty() ? $this->url('/missions', ['metier' => $f[0]]) : null);
            }, self::FAMILIES), 620);
            $page['tables'][] = UI::table('engineering', 'Missions par métier', $missions->pluck('trade')->unique()->count().' métier(s)', ['Métier', 'Famille', 'Missions', 'Ouvriers', 'Jours facturés', 'Montant brut', 'Part'], $missions->groupBy('trade')->sortByDesc(fn (Collection $g): int => $g->count())->map(fn (Collection $g, string $trade): array => self::row($trade, [
                UI::text($trade, true), UI::text(self::family($trade)), UI::text(self::number($g->count())), UI::text((string) $g->pluck('workerSlug')->unique()->count()), UI::text(self::number($g->sum('days'))), UI::text(self::money($g->sum('gross'))), UI::progress(self::percent($g->count(), $total)),
            ]))->values()->all(), 900);
            $page['tables'][] = UI::table('location_on', 'Missions par commune', $missions->pluck('commune')->unique()->count().' commune(s)', ['Commune', 'Missions', 'Chantiers', 'Montant brut', 'Part'], $missions->groupBy('commune')->sortByDesc(fn (Collection $g): int => $g->count())->map(fn (Collection $g, string $commune): array => self::row($commune, [
                UI::place($commune), UI::text(self::number($g->count())), UI::text((string) $g->pluck('siteSlug')->unique()->count()), UI::text(self::money($g->sum('gross'))), UI::progress(self::percent($g->count(), $total)),
            ], $this->url('/missions', ['zone' => $commune])))->values()->all(), 620);
        }

        if ($kind === 'chantiers' && $site) {
            $entries = $this->entries->where('siteSlug', $site->slug)->values();
            $perf = $this->performance($site, $entries);
            $page['kpis'] = [
                $kpi('Avancement', "{$site->progress} %", 'trending_up', 'g', $site->executedDays()." / {$site->planned_days} jours exécutés"), $kpi('Respect des coûts', $perf['costText'], 'savings', $perf['up'] ? 'g' : 'r', 'Main-d’œuvre prévue vs payée'),
                $kpi('Respect des délais', "{$perf['delay']} %", 'schedule', $perf['delay'] >= 85 ? 'g' : 'a', 'Avancement vs temps écoulé'), $kpi('Satisfaction', $perf['satisfactionText'], 'star', 'a', "{$perf['reviews']} avis éligible(s)"),
            ];
            $page['lines'] = [self::financeLines('Flux financiers du chantier', $this->series($entries), 'Budget', self::money((int) $site->budget))];
            $page['tables'][] = UI::table('group', 'Ouvriers mobilisés', $entries->pluck('workerSlug')->filter()->unique()->count().' ouvrier(s)', ['Ouvrier', 'Métier', 'Missions', 'Jours', 'Montant brut', 'Net reversé'], $entries->filter(fn (array $x): bool => (bool) $x['workerSlug'])->groupBy('workerSlug')->sortByDesc(fn (Collection $g): int => $g->count())->map(fn (Collection $g, string $slug): array => self::row($slug, [
                UI::person($g->first()['worker'], $g->first()['workerCode']), UI::text($g->first()['trade']), UI::text((string) $g->count()), UI::text(self::number($g->sum('days'))), UI::text(self::money($g->sum('gross'))), UI::text(self::money($g->where('revSt', 'Reversé')->sum('net'))),
            ], "/ouvriers/{$slug}"))->values()->all(), 900);
            $reviews = Review::query()->where('site_id', $site->id)->where('is_test', false)->orderByDesc('date')->get();
            $page['tables'][] = UI::table('reviews', 'Avis reçus', $reviews->count().' avis', ['Référence', 'Évalué(e)', 'Note', 'Date', 'Statut'], $reviews->map(fn (Review $r): array => self::row($r->reference, [
                UI::text($r->reference, true), UI::stacked($r->target, $r->kind === 'Équipe' ? 'Équipe' : $r->trade), UI::text($r->score() === null ? '—' : number_format($r->score(), 1, ',', '').' / 5'), UI::text($r->date->format('d/m/Y')), UI::badge($r->status, Review::TONES[$r->status] ?? 'n'),
            ], "/avis-litiges/avis/{$r->reference}"))->all(), 620, ['reviews', 'Aucun avis']);
        } elseif ($kind === 'chantiers') {
            $list = $this->sites->filter(fn (Site $s): bool => ! isset($this->filters['metier']) || $this->entries->contains('siteSlug', $s->slug))->map(fn (Site $s): array => ['site' => $s, ...$this->performance($s)])->sortByDesc(fn (array $x): int => $x['site']->progress)->values();
            $rated = $list->filter(fn (array $x): bool => $x['satisfaction'] !== null);
            $page['kpis'] = [
                $kpi('Chantiers suivis', (string) $list->count(), 'domain', 'b', $list->filter(fn (array $x): bool => $x['site']->progress >= 100)->count().' terminé(s)'),
                $kpi('Avancement moyen', round($list->avg(fn (array $x): int => $x['site']->progress) ?? 0).' %', 'trending_up', 'g', 'Tous chantiers filtrés'),
                $kpi('Respect des délais', round($list->avg('delay') ?? 0).' %', 'schedule', 'a', 'Moyenne'),
                $kpi('Satisfaction moyenne', $rated->isNotEmpty() ? number_format($rated->avg('satisfaction'), 1, ',', '').' / 5' : '—', 'star', 'a', $list->sum('reviews').' avis'),
            ];
            $page['tables'][] = UI::table('domain', 'Performance des chantiers', $list->count().' chantier(s) · cliquez pour le détail', ['Chantier', 'Promoteur', 'Commune', 'Avancement', 'Budget', 'Respect des coûts', 'Respect des délais', 'Satisfaction', 'Statut'], $list->map(fn (array $x): array => self::row($x['site']->slug, [
                UI::stacked($x['site']->name, $x['site']->reference), UI::text($x['site']->promoter_name), UI::text($x['site']->commune), UI::progress($x['site']->progress), UI::text(self::money((int) $x['site']->budget)),
                UI::badge(($x['up'] ? '▲ ' : '▼ ').$x['costText'], $x['up'] ? 'g' : 'r'), UI::text("{$x['delay']} %"), UI::text($x['satisfactionText']), UI::badge($x['site']->status->label(), $x['site']->status->tone()),
            ], $this->url("/chantiers/{$x['site']->slug}")))->all(), 1180);
        }

        if ($kind === 'ouvriers') {
            $ind = $this->workerIndicators();
            $total = $this->workers->count();
            $delta = fn (array $values): array => self::delta($values[count($values) - 1] ?? 0, $values[count($values) - 2] ?? 0);
            $page['kpis'] = [
                $kpi('Ouvriers inscrits', self::number($total), 'person', 'b', 'Filtres appliqués'), $kpi('Profils validés', self::number($ind['validated']->count()), 'verified', 'g', self::percent($ind['validated']->count(), $total).' % des inscrits'),
                $kpi('Disponibles', self::number($ind['available']->count()), 'engineering', 'a', self::percent($ind['available']->count(), $ind['validated']->count()).' % des validés'),
                $kpi('Taux de présence', $ind['presence'] === null ? '—' : round($ind['presence'] * 100).' %', 'data_usage', 'p', $this->attendances->count().' pointage(s)'),
            ];
            $page['tiles'] = [
                UI::tile('Ouvriers actifs cumulés', self::number(last($ind['cumulative']) ?: 0), 'person', 'g', ['sub' => $delta($ind['cumulative'])['text'].' vs mois préc.']),
                UI::tile('Ouvriers en mission ce mois', self::number(last($ind['active']) ?: 0), 'engineering', 'a', ['sub' => $delta($ind['active'])['text'].' vs mois préc.']),
                UI::tile('Présence du dernier jour', $ind['daily'] ? round(last($ind['daily'])).' %' : '—', 'data_usage', 'p', ['sub' => $delta($ind['daily'])['text'].' vs jour préc.']),
            ];
            $page['lines'] = [[
                'title' => 'Activité des ouvriers', 'sub' => '9 derniers mois', 'labels' => $ind['months'], 'note' => null, 'extra' => [],
                'series' => [
                    ['label' => 'Actifs cumulés', 'color' => 'var(--c-success)', 'values' => $ind['cumulative'], 'texts' => array_map(fn (int $v): string => "{$v} ouvrier(s)", $ind['cumulative'])],
                    ['label' => 'En mission', 'color' => 'var(--c-accent-600)', 'values' => $ind['active'], 'texts' => array_map(fn (int $v): string => "{$v} ouvrier(s)", $ind['active'])],
                ],
            ]];
            $page['charts'] = [UI::chart('Taux de présence par jour', 'Pointages des ouvriers filtrés', array_map(fn (string $d, float $v): array => [$d, $v, round($v).' %'], $ind['days'], $ind['daily']), 'var(--c-purple)')];
            $page['tables'][] = UI::table('category', 'Indicateurs par famille de métiers', count(self::FAMILIES).' familles', ['Famille', 'Inscrits', 'Validés', 'Disponibles', 'Note moyenne', 'Présence'], array_map(function (array $f): array {
                $workers = $this->workers->filter(fn (Worker $w): bool => self::family($w->trade) === $f[0]);
                $ids = $workers->pluck('id')->flip();
                $attendances = $this->attendances->filter(fn (Attendance $a): bool => isset($ids[$a->worker_id]));
                $rated = $workers->filter(fn (Worker $w): bool => (bool) $w->rating);

                return self::row($f[0], [
                    UI::text($f[0], true), UI::text((string) $workers->count()), UI::text((string) $workers->filter(fn (Worker $w): bool => $w->status === WorkerStatus::Validated)->count()),
                    UI::text((string) $workers->filter(fn (Worker $w): bool => $w->status === WorkerStatus::Validated && $w->availability === WorkerAvailability::Available)->count()),
                    UI::text($rated->isNotEmpty() ? number_format($rated->avg('rating'), 1, ',', '').' / 5' : '—'), UI::progress($attendances->isNotEmpty() ? self::percent($attendances->reject(fn (Attendance $a): bool => $this->absent($a))->count(), $attendances->count()) : 0),
                ], $workers->isNotEmpty() ? $this->url('/ouvriers', ['metier' => $f[0]]) : null);
            }, self::FAMILIES), 900);
            $workers = Worker::query()->get()->keyBy('slug');
            $page['tables'][] = UI::table('workspace_premium', 'Ouvriers les plus actifs', 'Top 12 · par nombre de missions', ['Ouvrier', 'Métier', 'Commune', 'Missions', 'Jours', 'Montant brut', 'Note'], $missions->groupBy('workerSlug')->sortByDesc(fn (Collection $g): int => $g->count())->take(12)->map(fn (Collection $g, string $slug): array => self::row($slug, [
                UI::person($g->first()['worker'], $g->first()['workerCode']), UI::text($g->first()['trade']), UI::text((string) ($workers[$slug]->commune ?? '—')), UI::text((string) $g->count()), UI::text(self::number($g->sum('days'))), UI::text(self::money($g->sum('gross'))),
                UI::text(($workers[$slug]->rating ?? null) ? number_format($workers[$slug]->rating, 1, ',', '').' / 5' : '—'),
            ], "/ouvriers/{$slug}"))->values()->all(), 1000);
        }

        if ($kind === 'bibliotheque') {
            $schedules = AnalyticsSchedule::query()->orderByDesc('id')->get();
            $next = $schedules->filter(fn (AnalyticsSchedule $s): bool => $s->isActive())->min('next_run');
            $page['kpis'] = [
                $kpi('Rapports générés', (string) AnalyticsReport::query()->count(), 'description', 'b', 'Bibliothèque'), $kpi('Programmations actives', (string) $schedules->filter(fn (AnalyticsSchedule $s): bool => $s->isActive())->count(), 'schedule', 'g', $schedules->count().' au total'),
                $kpi('Prochaine génération', $next ? CarbonImmutable::parse($next)->format('d/m/Y') : '—', 'event', 'a', 'Selon programmation'),
            ];
        }

        return $page;
    }

    /**
     * Lien interne au module qui conserve le rythme et les filtres.
     *
     * @param  array<string, mixed>  $extra
     */
    public function url(string $path, array $extra = []): string
    {
        $query = array_filter([...$this->filters, ...array_intersect_key($this->query, ['rythme' => true]), ...$extra], fn ($v): bool => $v !== null && $v !== '');

        return '/rapports'.$path.($query ? '?'.http_build_query($query) : '');
    }

    /**
     * Blocs exportés d’un rapport : [titre, colonnes, lignes]. La synthèse réunit l’évolution financière, les
     * familles de métiers, la performance des chantiers et les indicateurs des ouvriers.
     *
     * @return array<int, array{0: string, 1: array<int, string>, 2: array<int, array<int, string>>}>
     */
    public function blocks(string $kind): array
    {
        if ($kind === 'synthese') {
            $s = $this->series();

            return [
                ['Évolution financière', ['Période', 'Encaissements', 'Reversements', 'Commission nette'], array_map(fn (int $i): array => [$s['labels'][$i], (string) $s['collected'][$i], (string) $s['paidOut'][$i], (string) $s['net'][$i]], array_keys($s['keys']))],
                ...array_slice($this->blocks('missions'), 1, 1), ...$this->blocks('chantiers'), ...array_slice($this->blocks('ouvriers'), 0, 1),
            ];
        }
        $page = $this->page($kind);
        $cell = fn (array $c): string => match ($c['k']) {
            'prog' => "{$c['v']} %",
            'per', 'stk' => $c['v'].(($c['sub'] ?? null) ? " ({$c['sub']})" : ''),
            default => (string) $c['v'],
        };

        return [
            ['Indicateurs', ['Indicateur', 'Valeur'], array_map(fn (array $k): array => [$k['label'], $k['value']], $page['kpis'])],
            ...array_map(fn (array $t): array => [$t['title'], $t['columns'], array_map(fn (array $r): array => array_map($cell, $r['cells']), $t['rows'])], $page['tables']),
        ];
    }
}
