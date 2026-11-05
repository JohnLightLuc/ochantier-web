<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\SitePresenter;
use App\Support\SiteTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chantiers : liste et fiche, lues dans l’API (api/v1/admin/sites). Les décisions passent par SiteDecisionController.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class SiteController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'type' => 'construction_type_id', 'localisation' => 'commune_id', 'periode' => 'period', 'priorite' => 'priority', 'rapide' => 'todo', 'promoteur' => 'promoter_id', 'page' => 'page'];

    private const FILTER_KEYS = ['q', 'statut', 'type', 'localisation', 'periode', 'priorite', 'rapide', 'promoteur', 'tri', 'sens', 'page'];

    private const SORTS = ['chantier' => 'label', 'reference' => 'reference', 'debut' => 'start', 'fin' => 'end', 'effectif' => 'staffing', 'statut' => 'status'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('sites', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(SitePresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Sites/Index', [
            'sites' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => [...array_map($option, array_keys(SitePresenter::STATUS_LABELS), SitePresenter::STATUS_LABELS), $option('archives', 'Archivés')],
                'type' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['construction_types']),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
                'periode' => [$option('mois', 'Démarrage ce mois'), $option('a_venir', 'Démarrage à venir'), $option('fin_60', 'Fin dans les 60 jours')],
                'priorite' => [$option('basse', 'Basse'), $option('normale', 'Normale'), $option('haute', 'Haute'), $option('critique', 'Critique')],
            ],
            'kpis' => ['total' => $meta['counts']['total'], 'active' => $meta['counts']['active'], 'searching' => $meta['counts']['searching'], 'late' => $meta['counts']['todo']['retard']],
            'todo' => $meta['counts']['todo'],
            'can' => $this->can($request),
        ]);
    }

    /**
     * Export CSV de l’API (chantiers filtrés).
     */
    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('sites/export', $this->apiQuery($this->filters($request)));

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="chantiers.csv"',
        ]);
    }

    public function show(Request $request, string $site, ?string $tab = null): Response
    {
        $tab ??= '';
        $data = $this->api->get("sites/{$site}")->json('data');
        $view = SitePresenter::site($data);
        $base = "/chantiers/{$site}";

        return Inertia::render('Sites/Show', [
            'site' => $view,
            'tab' => $tab,
            'relations' => isset(SiteTabs::LABELS[$tab]) ? app(SiteTabs::class)->load($site, $tab, $request) : null,
            'tabCounts' => ['incidents' => $data['open_incidents_count']],
            'kpis' => SitePresenter::kpis($data),
            'banner' => SitePresenter::banner($data),
            'can' => $this->can($request),
            'header' => $this->header('Fiche du chantier', '', [['Chantiers', '/chantiers'], [$view['name'], $tab ? $base : null], ...($tab ? [[SiteTabs::LABELS[$tab]]] : [])]),
        ]);
    }

    /**
     * Anciennes adresses (slug local, onglet retiré) : une fiche de l’API rouvre sa vue d’ensemble ; un ancien slug lance la recherche du chantier dans la liste.
     */
    public function legacy(string $site): RedirectResponse
    {
        if (Str::isUuid($site)) {
            Inertia::flash('toast', 'Cet onglet n’existe plus : voici la fiche du chantier.');

            return to_route('sites.show', $site);
        }

        $name = Site::query()->where('slug', $site)->first()?->name ?? Str::of($site)->replace('-', ' ')->title()->value();
        Inertia::flash('toast', 'Ce lien renvoie vers une ancienne fiche : voici la recherche correspondante.');

        return to_route('sites.index', ['q' => $name]);
    }

    /**
     * @return array{update: bool, validate: bool, export: bool}
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('sites.update'), 'validate' => $allowed('sites.validate'), 'export' => $allowed('sites.export')];
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $filters = array_filter($request->only(self::FILTER_KEYS), fn ($value) => is_string($value) && $value !== '');

        // Liens du tableau de bord : « ?filtre=En cours ».
        $status = array_search($request->query('filtre'), SitePresenter::STATUS_LABELS, true);

        return is_string($status) && ! isset($filters['statut']) ? [...$filters, 'statut' => $status] : $filters;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function apiQuery(array $filters): array
    {
        $query = [];

        foreach (array_intersect_key($filters, self::QUERY) as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        if (($filters['statut'] ?? null) === 'archives') {
            $query['archived'] = 1;
        } elseif (isset($filters['statut'])) {
            $query['status'] = $filters['statut'];
        }

        if ($sort = self::SORTS[$filters['tri'] ?? ''] ?? null) {
            $query['sort'] = $sort;
            $query['direction'] = ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        return $query;
    }
}
