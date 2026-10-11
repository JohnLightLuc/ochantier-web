<?php

namespace App\Http\Controllers;

use App\Enums\WorkerStatus;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsSchedule;
use App\Models\Review;
use App\Models\Site;
use App\Support\AnalyticsInsights as A;
use App\Support\FinanceLedger;
use App\Support\FinanceReports;
use App\Support\PageBlocks as UI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Rapports & analyses : vue d’ensemble (volume d’affaires, évolution financière, répartition des missions,
 * indicateurs des ouvriers, performance des chantiers, rapports récents), rapports financier, missions, chantiers
 * et ouvriers, bibliothèque des rapports générés et programmations.
 */
class AnalyticsController extends Controller
{
    /**
     * Rapports exportables : clé → libellé (la synthèse réunit les quatre rapports).
     *
     * @return array<string, string>
     */
    public static function exportable(): array
    {
        return ['synthese' => 'Synthèse des rapports', ...collect(A::KINDS)->except('bibliotheque')->map(fn (array $k): string => $k[0])->all()];
    }

    private function period(): string
    {
        $t = A::today();

        return 'Janvier – '.Str::ucfirst($t->locale('fr')->translatedFormat('F')).' '.$t->year;
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<int, array{0: string, 1?: string|null}>  $crumbs
     */
    private function render(A $ins, string $section, array $props, string $title, string $subtitle, array $crumbs = []): Response
    {
        $allSites = Site::query()->whereNotNull('promoter_name')->where('status', '!=', 'draft')->orderBy('name')->get(['slug', 'name', 'commune']);
        $select = fn (string $key, string $label, array $options): array => ['key' => $key, 'label' => $label, 'value' => $ins->filters[$key] ?? '', 'options' => [['value' => '', 'label' => $label], ...$options]];

        return Inertia::render('Analytics/Page', [
            'page' => $section,
            'nav' => [['label' => 'Vue d’ensemble', 'icon' => 'dashboard', 'href' => $ins->url(''), 'active' => $section === ''], ...array_map(fn (string $k, array $v): array => ['label' => $v[0], 'icon' => $v[1], 'href' => $ins->url("/{$k}"), 'active' => $section === $k], array_keys(A::KINDS), A::KINDS)],
            'period' => ['key' => '', 'label' => $this->period(), 'range' => $this->period(), 'from' => '', 'to' => ''], 'periods' => (object) [],
            'query' => array_filter(array_map('strval', array_diff_key($ins->query, ['page' => true])), fn (string $v): bool => $v !== ''),
            'search' => false, 'banner' => null, 'views' => [], 'kpis' => [], 'chips' => [], 'alerts' => [], 'tiles' => [], 'toolbar' => [], 'flow' => null, 'charts' => [], 'panels' => [], 'tables' => [],
            'timeline' => [], 'missing' => null, 'sheets' => (object) [], 'tabs' => [], 'profile' => null, 'lines' => [], 'donuts' => [],
            'selects' => [
                $select('chantier', 'Tous les chantiers', $allSites->map(fn (Site $s): array => ['value' => $s->slug, 'label' => $s->name])->all()),
                $select('zone', 'Toutes les zones', $allSites->pluck('commune')->unique()->sort()->values()->map(fn (string $c): array => ['value' => $c, 'label' => $c])->all()),
                $select('metier', 'Tous les métiers', array_map(fn (array $f): array => ['value' => $f[0], 'label' => $f[0]], A::FAMILIES)),
                ['key' => 'rythme', 'label' => 'Rythme', 'value' => $ins->rhythm === 'Mensuel' ? '' : $ins->rhythm, 'options' => array_map(fn (string $r): array => ['value' => $r === 'Mensuel' ? '' : $r, 'label' => "Rythme : {$r}"], A::RHYTHMS)],
            ],
            ...$props,
            'header' => $this->header($title, $subtitle, $crumbs ? [['Rapports', $ins->url('')], ...$crumbs] : []),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actions(A $ins, string $kind): array
    {
        return [UI::button('Programmer', 'schedule', $this->scheduleForm(in_array($kind, array_keys(A::KINDS), true) && $kind !== 'bibliotheque' ? $kind : 'financier')), UI::button('Exporter le rapport', 'download', UI::download($ins->url('/generer', ['rapport' => $kind, 'format' => 'Excel'])), 'primary')];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleForm(string $kind): array
    {
        return UI::form('Programmer un rapport', 'Le rapport sera généré automatiquement avec les filtres par défaut et envoyé aux destinataires.', 'schedule', 'Programmer', '/rapports/programmations', [
            ['name' => 'kind', 'label' => 'Rapport', 'type' => 'select', 'required' => true, 'options' => collect(self::exportable())->except('synthese')->map(fn (string $l, string $k): array => ['value' => $k, 'label' => $l])->values()->all(), 'full' => true],
            ['name' => 'frequency', 'label' => 'Fréquence', 'type' => 'select', 'required' => true, 'options' => AnalyticsSchedule::FREQUENCIES],
            ['name' => 'next_run', 'label' => 'Première génération', 'type' => 'date', 'required' => true],
            ['name' => 'recipients', 'label' => 'Destinataires', 'required' => true, 'full' => true],
            ['name' => 'format', 'label' => 'Format', 'type' => 'select', 'required' => true, 'options' => FinanceReports::FORMATS],
        ], 'primary', [], ['kind' => $kind, 'frequency' => 'Mensuel', 'next_run' => A::today()->addDays(2)->format('Y-m-d'), 'recipients' => 'Direction générale', 'format' => 'PDF']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function reportCard(AnalyticsReport $r): array
    {
        $ext = ['Excel' => 'XLS', 'CSV' => 'CSV'][$r->format] ?? 'PDF';

        return UI::card($ext === 'PDF' ? 'picture_as_pdf' : 'table_view', $ext, $r->name, "{$r->size} · ".$r->generated_on->format('d/m/Y')." · {$r->author}", 'Télécharger', $ext === 'PDF' ? 'r' : 'b', UI::download("/rapports/bibliotheque/{$r->id}/telechargement"));
    }

    public function index(Request $request): Response
    {
        $ins = new A($request->query());
        $t = A::today();
        $s = $ins->series();
        $months = FinanceLedger::months();
        $volume = fn (array $list): int => (int) $ins->entries->filter(fn (array $x): bool => in_array(substr((string) ($x['d']['presta'] ?? $x['d']['op']), 0, 7), $list, true))->sum('vol');
        $delta = A::delta($volume(array_slice($months, -3)), $volume(array_slice($months, -6, 3)));
        $yearVolume = (int) $ins->entries->filter(fn (array $x): bool => str_starts_with((string) ($x['d']['presta'] ?? $x['d']['op']), $ins->year))->sum('vol');
        $done = $ins->sites->filter(fn (Site $site): bool => $site->progress >= 100 || in_array($site->status->label(), ['Terminé', 'Clôturé'], true))->count();
        $validated = $ins->workers->filter(fn ($w): bool => $w->status === WorkerStatus::Validated);
        $week = $ins->attendances->filter(fn ($a): bool => $a->date->diffInDays($t) <= 7)->pluck('worker_id')->flip();
        $occupation = A::percent($validated->filter(fn ($w): bool => isset($week[$w->id]))->count(), $validated->count());
        $satisfaction = $ins->reviews->isNotEmpty() ? $ins->reviews->avg(fn (Review $r): ?float => $r->score()) : null;
        $collectedYear = (int) $ins->entries->filter(fn (array $x): bool => $x['paid'] && str_starts_with((string) ($x['d']['enc'] ?? $x['d']['pay']), $ins->year))->sum('comNet');
        $workers = $ins->page('ouvriers');
        $performance = $ins->sites->filter(fn (Site $site): bool => ! isset($ins->filters['metier']) || $ins->entries->contains('siteSlug', $site->slug))->sortByDesc('progress')->take(4)->map(function (Site $site) use ($ins): array {
            $p = $ins->performance($site);

            return UI::card('domain', "{$site->progress} % d’avancement", $site->name, "Coûts {$p['costText']} · délais {$p['delay']} % · satisfaction {$p['satisfactionText']}", $p['up'] ? '▲ coûts' : '▼ coûts', $p['up'] ? 'g' : 'r', UI::link($ins->url("/chantiers/{$site->slug}")));
        })->values()->all();

        return $this->render($ins, '', [
            'kpis' => [
                ['label' => 'Volume d’affaires', 'value' => A::money($yearVolume), 'icon' => 'paid', 'tone' => 'a', 'hint' => "{$delta['text']} vs trimestre préc.", 'action' => UI::link($ins->url('/financier'))],
                ['label' => 'Chantiers réalisés', 'value' => (string) $done, 'icon' => 'domain', 'tone' => 'b', 'hint' => $ins->sites->count().' chantiers suivis', 'action' => UI::link($ins->url('/chantiers'))],
                ['label' => 'Taux d’occupation', 'value' => "{$occupation} %", 'icon' => 'pie_chart', 'tone' => 'r', 'hint' => 'Ouvriers validés pointés sur 7 jours', 'action' => UI::link($ins->url('/ouvriers'))],
                ['label' => 'Satisfaction moyenne', 'value' => $satisfaction === null ? '—' : number_format($satisfaction, 1, ',', '').' / 5', 'icon' => 'star', 'tone' => 'a', 'hint' => $ins->reviews->count().' avis éligibles', 'action' => UI::link('/avis-litiges/avis')],
            ],
            'toolbar' => $this->actions($ins, 'synthese'),
            'lines' => [A::financeLines('Évolution financière', $s, 'CA net', A::money($collectedYear))],
            'donuts' => [$ins->split()],
            'tiles' => $workers['tiles'],
            'panels' => [
                UI::cards('domain', 'Performance des chantiers', $performance, UI::button('Tous les chantiers', 'arrow_forward', UI::link($ins->url('/chantiers')))),
                UI::cards('description', 'Rapports récents', AnalyticsReport::query()->latest('generated_on')->latest('id')->take(3)->get()->map(fn (AnalyticsReport $r): array => self::reportCard($r))->all(), UI::button('Bibliothèque', 'arrow_forward', UI::link($ins->url('/bibliotheque')))),
            ],
        ], 'Rapports & analyses', 'Pilotez les performances opérationnelles et financières de la plateforme');
    }

    public function show(Request $request, string $kind): Response
    {
        $ins = new A($request->query());
        $page = $ins->page($kind);
        $props = [...$page, 'toolbar' => $this->actions($ins, $kind)];
        if ($kind === 'bibliotheque') {
            $props['toolbar'] = [UI::button('Programmer', 'schedule', $this->scheduleForm('financier')), UI::button('Générer un rapport', 'add', UI::form('Générer un rapport', 'Le rapport utilise le rythme et les filtres actuellement sélectionnés. Il est ajouté à la bibliothèque.', 'description', 'Générer', '/rapports/bibliotheque', [
                ['name' => 'kind', 'label' => 'Rapport', 'type' => 'select', 'required' => true, 'options' => collect(self::exportable())->map(fn (string $l, string $k): array => ['value' => $k, 'label' => $l])->values()->all(), 'full' => true],
                ['name' => 'format', 'label' => 'Format', 'type' => 'select', 'required' => true, 'options' => FinanceReports::FORMATS],
            ], 'primary', array_filter([...$ins->filters, 'rythme' => $ins->rhythm]), ['format' => 'Excel']), 'primary')];
            $schedules = AnalyticsSchedule::query()->orderByDesc('id')->get();
            $reports = AnalyticsReport::query()->latest('generated_on')->latest('id')->get();
            $props['tables'] = [
                UI::table('schedule', 'Rapports programmés', $schedules->count().' programmation(s)', ['Rapport', 'Fréquence', 'Prochaine génération', 'Destinataires', 'Format', 'Statut'], $schedules->map(fn (AnalyticsSchedule $x): array => UI::row((string) $x->id, [
                    UI::text(A::KINDS[$x->kind][0] ?? $x->kind, true), UI::text($x->frequency), UI::text($x->next_run->format('d/m/Y')), UI::text($x->recipients), UI::text($x->format), UI::badge($x->status, $x->isActive() ? 'g' : 'n'),
                ], null, [
                    ['bolt', 'Générer maintenant', UI::download("/rapports/generer?rapport={$x->kind}&format={$x->format}")],
                    [$x->isActive() ? 'pause_circle' : 'play_circle', $x->isActive() ? 'Suspendre' : 'Réactiver', UI::post("/rapports/programmations/{$x->id}/statut")],
                    ['delete', 'Supprimer', UI::confirm('Supprimer la programmation ?', (A::KINDS[$x->kind][0] ?? $x->kind).' · '.mb_strtolower($x->frequency).'. Les rapports déjà générés sont conservés.', 'delete', 'Supprimer', "/rapports/programmations/{$x->id}/suppression", 'danger')],
                ]))->all(), 980, ['schedule', 'Aucune programmation']),
                UI::table('description', 'Bibliothèque', $reports->count().' rapport(s) · cliquez pour télécharger', ['Rapport', 'Format', 'Taille', 'Généré le', 'Par', 'Filtres', 'Rythme'], $reports->map(fn (AnalyticsReport $r): array => UI::row((string) $r->id, [
                    UI::text($r->name, true), UI::badge($r->format, $r->format === 'PDF' ? 'r' : 'b'), UI::text($r->size), UI::text($r->generated_on->format('d/m/Y')), UI::text($r->author), UI::text($r->filters), UI::text($r->rhythm),
                ], UI::download("/rapports/bibliotheque/{$r->id}/telechargement"), [['download', 'Télécharger', UI::download("/rapports/bibliotheque/{$r->id}/telechargement")]]))->all(), 1000, ['description', 'Aucun rapport généré']),
            ];
        }

        return $this->render($ins, $kind, $props, A::KINDS[$kind][0], A::KINDS[$kind][2], [[A::KINDS[$kind][0]]]);
    }

    public function site(Request $request, Site $site): Response
    {
        $ins = new A($request->query());
        $page = $ins->page('chantiers', $site->load('promoter'));

        return $this->render($ins, 'chantiers', [...$page, 'toolbar' => [UI::button('Fiche chantier', 'domain', UI::link("/chantiers/{$site->slug}")), ...$this->actions($ins, 'chantiers')]], $site->name, "{$site->reference} · {$site->promoter_name} · {$site->commune}", [['Performance des chantiers', $ins->url('/chantiers')], [$site->name]]);
    }

    /**
     * Fichier d’un rapport : CSV par sections, tableau Excel ou version imprimable (PDF).
     *
     * @param  array<int, array{0: string, 1: array<int, string>, 2: array<int, array<int, string>>}>  $blocks
     */
    private static function file(string $name, string $format, array $meta, array $blocks): FileResponse
    {
        $base = Str::slug($name);
        if ($format === 'CSV') {
            $line = fn (array $values): string => implode(';', array_map(fn ($v): string => '"'.str_replace('"', '""', (string) $v).'"', $values));
            $lines = [...array_map($line, $meta), ''];
            foreach ($blocks as [$title, $columns, $rows]) {
                $lines = [...$lines, $line([$title]), $line($columns), ...array_map($line, $rows), ''];
            }

            return response("\u{FEFF}".implode("\n", $lines), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}.csv\""]);
        }
        $html = view('exports.analytics-report', ['name' => $name, 'meta' => $meta, 'blocks' => $blocks, 'print' => $format === 'PDF'])->render();

        return $format === 'Excel'
            ? response($html, 200, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}.xls\""])
            : response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}-imprimable.html\""]);
    }

    private static function reportName(string $kind): string
    {
        $t = A::today();

        return self::exportable()[$kind].' · '.A::MONTHS[$t->month - 1].' '.$t->year;
    }

    /**
     * Enregistre un rapport dans la bibliothèque.
     *
     * @param  array<int, array{0: string, 1: array<int, string>, 2: array<int, array<int, string>>}>  $blocks
     */
    private static function record(A $ins, string $kind, string $format, array $blocks): AnalyticsReport
    {
        $rows = array_sum(array_map(fn (array $b): int => count($b[2]), $blocks));

        return AnalyticsReport::create([
            'kind' => $kind, 'name' => self::reportName($kind), 'format' => $format, 'rows' => $rows, 'size' => str_replace('.', ',', (string) max(0.2, round($rows * 0.012, 1))).' Mo',
            'generated_on' => A::today(), 'author' => auth()->user()?->name ?? 'Système', 'filters' => $ins->filterSummary(), 'rhythm' => $ins->rhythm,
        ]);
    }

    /**
     * Génère et télécharge un rapport (rythme et filtres de la requête) ; il est ajouté à la bibliothèque.
     */
    public function generate(Request $request): FileResponse
    {
        $data = $request->validate(['rapport' => ['required', Rule::in(array_keys(self::exportable()))], 'format' => ['required', Rule::in(FinanceReports::FORMATS)]]);
        $ins = new A($request->query());
        $blocks = $ins->blocks($data['rapport']);
        $report = self::record($ins, $data['rapport'], $data['format'], $blocks);

        return self::file($report->name, $data['format'], [['Généré le', now()->format('d/m/Y H:i')." par {$report->author}"], ['Rythme', $ins->rhythm], ['Filtres', $report->filters], ['Source', 'O’CHANTIER ADMIN · données de la plateforme']], $blocks);
    }

    /**
     * Ajoute un rapport à la bibliothèque (téléchargeable ensuite).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(self::exportable()))], 'format' => ['required', Rule::in(FinanceReports::FORMATS)], 'chantier' => ['nullable', 'string'], 'zone' => ['nullable', 'string'], 'metier' => ['nullable', 'string'], 'rythme' => ['nullable', Rule::in(A::RHYTHMS)]]);
        $ins = new A($data);
        $report = self::record($ins, $data['kind'], $data['format'], $ins->blocks($data['kind']));
        Inertia::flash('toast', "{$report->name} ajouté à la bibliothèque · cliquez pour le télécharger.");

        return redirect('/rapports/bibliotheque');
    }

    /**
     * Télécharge un rapport de la bibliothèque, reconstruit avec son rythme à partir des données actuelles.
     */
    public function download(AnalyticsReport $report): FileResponse
    {
        $ins = new A(['rythme' => $report->rhythm]);

        return self::file($report->name, $report->format, [['Généré le', $report->generated_on->format('d/m/Y')." par {$report->author}"], ['Rythme', $report->rhythm], ['Filtres', $report->filters], ['Source', 'O’CHANTIER ADMIN · données de la plateforme']], $ins->blocks(array_key_exists($report->kind, self::exportable()) ? $report->kind : 'synthese'));
    }

    public function schedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(collect(self::exportable())->except('synthese')->all()))], 'frequency' => ['required', Rule::in(AnalyticsSchedule::FREQUENCIES)],
            'next_run' => ['required', 'date', 'after_or_equal:'.A::today()->format('Y-m-d')], 'recipients' => ['required', 'string', 'min:3', 'max:200'], 'format' => ['required', Rule::in(FinanceReports::FORMATS)],
        ]);
        $s = AnalyticsSchedule::create([...$data, 'recipients' => trim($data['recipients']), 'status' => 'Actif']);
        Inertia::flash('toast', 'Rapport programmé · '.mb_strtolower($s->frequency).' à partir du '.$s->next_run->format('d/m/Y').'.');

        return back();
    }

    public function toggle(AnalyticsSchedule $schedule): RedirectResponse
    {
        $schedule->update(['status' => $schedule->isActive() ? 'Suspendu' : 'Actif']);
        Inertia::flash('toast', 'Programmation '.($schedule->isActive() ? 'réactivée' : 'suspendue').'.');

        return back();
    }

    public function destroy(AnalyticsSchedule $schedule): RedirectResponse
    {
        $schedule->delete();
        Inertia::flash('toast', 'Programmation supprimée.');

        return back();
    }
}
