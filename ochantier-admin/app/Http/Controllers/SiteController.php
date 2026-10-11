<?php

namespace App\Http\Controllers;

use App\Enums\SiteActivityCategory;
use App\Enums\SiteDocumentType;
use App\Enums\SitePriority;
use App\Enums\SiteStatus;
use App\Http\Requests\SiteRequest;
use App\Http\Resources\SiteActivityResource;
use App\Http\Resources\SiteDocumentResource;
use App\Http\Resources\SiteResource;
use App\Models\Promoter;
use App\Models\Site;
use App\Models\SiteDocument;
use App\Models\SiteIncident;
use App\Support\SiteInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    public const TABS = [
        '' => 'Vue d’ensemble',
        'equipe' => 'Équipe et pointages',
        'incidents' => 'Incidents et remplacements',
        'documents' => 'Documents',
        'historique' => 'Historique',
    ];

    /**
     * Colonnes triables : clé d’URL → champ de la ligne.
     */
    private const SORTABLE = [
        'chantier' => 'name', 'reference' => 'reference', 'promoteur' => 'promoter', 'localisation' => 'commune', 'type' => 'type',
        'debut' => 'startsOn', 'fin' => 'endsOn', 'jours' => 'executedDays', 'progression' => 'progress', 'effectif' => 'assigned',
        'pointages' => 'timesheets', 'paiements' => 'payments', 'alerte' => 'alert', 'statut' => 'status',
    ];

    /**
     * Ordre de gravité des niveaux d’alerte, pour le tri.
     */
    private const ALERT_ORDER = ['Faible' => 0, 'Moyenne' => 1, 'Élevée' => 2, 'Critique' => 3];

    private const VIEWS = ['tableau', 'cartes', 'calendrier', 'carte'];

    private const PAYMENT_FILTERS = ['a-jour' => 'À jour', 'en-attente' => 'En attente', 'a-autoriser' => 'À autoriser', 'retard' => 'Retard de paiement'];

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'statut', 'promoteur', 'type', 'localisation', 'periode', 'progression', 'recrutement', 'incident', 'paiements', 'priorite', 'rapide', 'alerte', 'tri', 'sens', 'page', 'vue']);

        // Liens du tableau de bord : « ?filtre=En cours » → filtre de statut.
        if ($request->filled('filtre') && ! isset($filters['statut'])) {
            $status = collect(SiteStatus::cases())->first(fn (SiteStatus $s): bool => $s->label() === $request->query('filtre'));
            $filters['statut'] = $status?->value;
        }

        $insights = new SiteInsights;
        $sites = Site::with('incidents', 'documents')->orderBy('id')->get();
        $quick = array_key_exists($filters['rapide'] ?? '', SiteInsights::QUICK) ? $filters['rapide'] : null;
        $view = in_array($filters['vue'] ?? '', self::VIEWS, true) ? $filters['vue'] : 'tableau';

        $rows = $sites->filter(fn (Site $site): bool => $this->matches($site, $filters, $insights))->map(fn (Site $site): array => $insights->row($site));
        $rows = $this->sorted($rows, $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page);
        $visible = $sites->filter(fn (Site $site): bool => ! $quick || $insights->matchesQuick($site, $quick))->values();
        $option = fn (string $value, ?string $label = null): array => ['value' => $value, 'label' => $label ?? $value];

        return Inertia::render('Sites/Index', [
            'sites' => [
                'data' => $paginator->items(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => array_filter($filters),
            'view' => $view,
            'filterOptions' => [
                'statut' => array_map(fn (SiteStatus $s): array => $option($s->value, $s->label()), SiteStatus::cases()),
                'promoteur' => $sites->pluck('promoter_name')->unique()->sort()->values()->map(fn (string $p): array => $option($p))->all(),
                'type' => array_map(fn (string $t): array => $option($t), SiteRequest::TYPES),
                'localisation' => $sites->pluck('commune')->unique()->sort()->values()->map(fn (string $c): array => $option($c))->all(),
                'periode' => [$option('mois', 'Démarrage ce mois'), $option('fin-60', 'Fin dans les 60 jours'), $option('a-venir', 'Démarrage à venir')],
                'progression' => [$option('moins-25', 'Moins de 25 %'), $option('25-75', '25 à 75 %'), $option('plus-75', 'Plus de 75 %')],
                'recrutement' => [$option('incomplet', 'Effectif incomplet'), $option('complet', 'Effectif complet')],
                'incident' => [$option('avec', 'Avec incident'), $option('sans', 'Sans incident')],
                'paiements' => array_map(fn (string $value, string $label): array => $option($value, $label), array_keys(self::PAYMENT_FILTERS), self::PAYMENT_FILTERS),
                'priorite' => array_map(fn (SitePriority $p): array => $option($p->value, $p->label()), SitePriority::cases()),
            ],
            'kpis' => collect(SiteInsights::QUICK)->map(fn (array $q, string $key): array => [
                'key' => $key, 'label' => $q[0], 'icon' => $q[1], 'tone' => $q[2],
                'value' => $sites->filter(fn (Site $site): bool => $insights->matchesQuick($site, $key))->count(),
            ])->values()->all(),
            'todo' => collect(SiteInsights::ALERTS)->map(fn (string $label, string $key): int => $sites->filter(fn (Site $site): bool => $insights->matchesAlert($site, $key))->count())->all(),
            'cards' => in_array($view, ['cartes', 'carte'], true) ? $visible->map(fn (Site $site): array => $insights->card($site))->all() : [],
            'events' => $view === 'calendrier' ? $insights->events($visible) : [],
            'communes' => $view === 'carte' ? Site::COORDINATES : [],
            'referenceDate' => Site::referenceDate()->format('Y-m-d'),
            'options' => [
                'promoters' => $sites->pluck('promoter_name')->merge(Promoter::query()->pluck('name'))->unique()->sort()->values()->all(),
                'types' => SiteRequest::TYPES,
                'communes' => SiteRequest::communes(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matches(Site $site, array $filters, SiteInsights $insights): bool
    {
        $flags = $insights->flags($site);
        $today = Site::referenceDate();
        $q = Str::lower(trim((string) ($filters['q'] ?? '')));

        $checks = [
            $q === '' || Str::contains(Str::lower(implode(' ', [$site->name, $site->reference, $site->manager, $site->promoter_name, $site->commune])), $q),
            empty($filters['rapide']) || ! array_key_exists($filters['rapide'], SiteInsights::QUICK) || $insights->matchesQuick($site, $filters['rapide']),
            empty($filters['alerte']) || ! array_key_exists($filters['alerte'], SiteInsights::ALERTS) || $insights->matchesAlert($site, $filters['alerte']),
            empty($filters['statut']) || $site->status->value === $filters['statut'],
            empty($filters['promoteur']) || $site->promoter_name === $filters['promoteur'],
            empty($filters['type']) || $site->type === $filters['type'],
            empty($filters['localisation']) || $site->commune === $filters['localisation'],
            empty($filters['priorite']) || $site->priority->value === $filters['priorite'],
            empty($filters['paiements']) || $flags['payments'] === (self::PAYMENT_FILTERS[$filters['paiements']] ?? null),
            match ($filters['incident'] ?? null) {
                'avec' => $flags['incidents'] > 0,
                'sans' => $flags['incidents'] === 0,
                default => true,
            },
            match ($filters['recrutement'] ?? null) {
                'incomplet' => $flags['staffing'] === 'Incomplet',
                'complet' => $flags['staffing'] !== 'Incomplet',
                default => true,
            },
            match ($filters['progression'] ?? null) {
                'moins-25' => $site->progress < 25,
                '25-75' => $site->progress >= 25 && $site->progress <= 75,
                'plus-75' => $site->progress > 75,
                default => true,
            },
            match ($filters['periode'] ?? null) {
                'mois' => $site->starts_on->format('Y-m') === $today->format('Y-m'),
                'fin-60' => $site->ends_on->betweenIncluded($today, $today->addDays(60)),
                'a-venir' => $site->starts_on->greaterThan($today),
                default => true,
            },
        ];

        return ! in_array(false, $checks, true);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sorted(Collection $rows, array $filters): Collection
    {
        $field = self::SORTABLE[$filters['tri'] ?? ''] ?? null;
        if (! $field) {
            return $rows->values();
        }

        $value = fn (array $row): int|string => match ($field) {
            'alert' => self::ALERT_ORDER[$row['alert']['label']] ?? 0,
            'timesheets', 'payments', 'status' => Str::ascii($row[$field]['label']),
            default => is_string($row[$field]) ? Str::lower(Str::ascii($row[$field])) : $row[$field],
        };

        return $rows->sortBy($value, SORT_NATURAL, ($filters['sens'] ?? 'asc') === 'desc')->values();
    }

    /**
     * Export CSV de tous les chantiers.
     */
    public function export(): StreamedResponse
    {
        $sites = Site::query()->orderBy('id')->get();

        return response()->streamDownload(function () use ($sites): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Référence', 'Chantier', 'Promoteur', 'Localisation', 'Type', 'Début', 'Fin', 'Progression', 'Ouvriers', 'Statut'], ';');
            foreach ($sites as $s) {
                fputcsv($out, [$s->reference, $s->name, $s->promoter_name, $s->commune, $s->type, $s->starts_on->format('Y-m-d'), $s->ends_on->format('Y-m-d'), $s->progress, "{$s->assigned_workers}/{$s->required_workers}", $s->status->label()], ';');
            }
            fclose($out);
        }, 'chantiers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Création administrative exceptionnelle, tracée dans le journal d’audit.
     */
    public function store(SiteRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $site = DB::transaction(function () use ($data): Site {
            $start = CarbonImmutable::parse($data['starts_on']);
            $end = CarbonImmutable::parse($data['ends_on']);
            [$lat, $lng] = Site::COORDINATES[$data['commune']];
            $name = trim($data['name']);

            $site = Site::create([
                ...Site::DEFAULTS,
                'slug' => Site::uniqueSlug($name),
                'reference' => Site::nextReference(),
                'name' => $name,
                'promoter_id' => Promoter::query()->where('name', $data['promoter'])->value('id'),
                'promoter_name' => $data['promoter'],
                'commune' => $data['commune'],
                'city' => Site::cityOf($data['commune']),
                'address' => $data['commune'],
                'latitude' => $lat,
                'longitude' => $lng,
                'type' => $data['type'],
                'starts_on' => $start,
                'ends_on' => $end,
                'planned_days' => Site::workingDaysBetween($start, $end),
                'required_workers' => (int) $data['required_workers'],
                'status' => SiteStatus::Planned,
                'priority' => SitePriority::Normal,
                'submitted_on' => today(),
            ]);
            $site->createMissingDocuments();
            $site->logActivity(SiteActivityCategory::Creation, 'a créé le chantier (création administrative exceptionnelle)', null, SiteStatus::Planned->label(), trim($data['justification']));

            return $site;
        });

        Inertia::flash('toast', 'Chantier créé · action tracée dans le journal d’audit.');

        return to_route('sites.show', $site);
    }

    /**
     * Fiche détaillée et ses onglets.
     */
    public function show(Site $site, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $site->load('documents', 'incidents', 'activities');
        $insights = new SiteInsights;
        $flags = $insights->flags($site);
        $promoterSlug = $insights->promoterSlug($site);
        $promoterBase = $promoterSlug ? "/promoteurs/{$promoterSlug}" : null;
        $base = "/chantiers/{$site->slug}";
        $timesheets = $insights->timesheets($site);

        return Inertia::render('Sites/Show', [
            'site' => SiteResource::make($site)->resolve(),
            'tab' => $tab,
            'kpis' => $insights->kpis($site),
            'tabCounts' => $insights->tabCounts($site),
            'banner' => $insights->banner($site),
            'alerts' => $insights->alerts($site),
            'flags' => [
                'staffing' => SiteInsights::badge($flags['staffing']),
                'vacancies' => $flags['vacancies'],
                'late' => $flags['late'],
                'lateTimesheets' => $timesheets->where('timesheet.status', 'En retard')->count(),
                'absences' => $timesheets->where('timesheet.status', 'Absence')->count(),
            ],
            'milestones' => array_map(fn (array $m): array => [...$m, 'tone' => SiteInsights::badge($m['status'])['tone']], $site->milestones()),
            'team' => $insights->team($site)->map(fn (array $row): array => [
                'id' => "{$row['worker']->slug}-{$row['assignment']['id']}",
                'slug' => $row['worker']->slug,
                'name' => $row['worker']->name,
                'code' => $row['worker']->code,
                'trade' => $row['worker']->trade,
                'start' => $row['assignment']['start'],
                'end' => $row['assignment']['end'],
                'pay' => $row['assignment']['pay'],
                'status' => $row['assignment']['status'],
                'availability' => ['label' => $row['worker']->availability->label(), 'tone' => $row['worker']->availability->tone()],
            ])->values()->all(),
            'timesheets' => $timesheets->map(fn (array $row): array => [
                'id' => "{$row['worker']->slug}-{$row['timesheet']['id']}",
                'slug' => $row['worker']->slug,
                'name' => $row['worker']->name,
                'trade' => $row['worker']->trade,
                ...collect($row['timesheet'])->only(['date', 'in', 'out', 'status', 'anomaly', 'validation'])->all(),
            ])->all(),
            'replacements' => $insights->replacements($site)->map(fn (array $row): array => [
                'id' => $row['case']['id'],
                'slug' => $row['worker']->slug,
                'name' => $row['worker']->name,
                'trade' => $row['worker']->trade,
                'start' => $row['case']['start'],
                'end' => $row['case']['end'],
                'reason' => $row['case']['reason'],
                'replacement' => $row['case']['chosen']['name'] ?? null,
                'status' => $row['case']['status'],
            ])->values()->all(),
            'offers' => $insights->offers($site)->map(fn (array $o): array => [
                'id' => $o['id'], 'name' => $o['name'], 'trade' => $o['trade'], 'type' => $o['type'], 'applications' => $o['applications'],
                'budget' => $o['budget'], 'due' => $o['due'], 'status' => $this->badge($o['status']), 'href' => "{$promoterBase}/offres/{$o['id']}",
            ])->all(),
            'payments' => $insights->payments($site)->map(fn (array $p): array => [
                'ref' => $p['ref'], 'type' => $p['type'], 'method' => $p['method'], 'amount' => $p['amount'], 'date' => $p['date'],
                'status' => $this->badge($p['status']), 'href' => "{$promoterBase}/paiements/{$p['ref']}",
            ])->all(),
            'incidents' => $site->incidents->map(fn (SiteIncident $i): array => [
                'id' => $i->id, 'reference' => $i->reference, 'date' => $i->occurred_on->format('Y-m-d'), 'type' => $i->type,
                'severity' => ['label' => $i->severity->label(), 'tone' => $i->severity->tone()],
                'description' => $i->description, 'reportedBy' => $i->reported_by, 'resolution' => $i->resolution,
                'status' => ['value' => $i->status->value, 'label' => $i->status->label(), 'tone' => $i->status->tone()],
            ])->values()->all(),
            'documents' => SiteDocumentResource::collection($site->documents->sortBy(fn (SiteDocument $d): int => array_search($d->type, SiteDocumentType::cases(), true))->values())->resolve(),
            'activities' => SiteActivityResource::collection($site->activities->take(300))->resolve(),
            'info' => SiteInsights::editable($site),
            'acceptanceVerified' => $insights->acceptanceVerified($site),
            'promoterHref' => $promoterBase ?? '/promoteurs?q='.rawurlencode($site->promoter_name),
            'header' => $this->header('Fiche du chantier', '', [['Chantiers', '/chantiers'], [$site->name, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Fiche texte téléchargeable.
     */
    public function sheet(Site $site): HttpResponse
    {
        $content = implode("\n", [
            "{$site->name} ({$site->reference})",
            $site->promoter_name,
            "{$site->commune}, {$site->city}",
            'Statut : '.$site->status->label(),
            "Progression : {$site->progress} %",
            "Effectif : {$site->assigned_workers} / {$site->required_workers}",
            'Période : '.$site->starts_on->format('d/m/Y').' → '.$site->ends_on->format('d/m/Y'),
        ])."\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"chantier-{$site->reference}.txt\"",
        ]);
    }
}
