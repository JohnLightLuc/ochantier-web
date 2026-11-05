<?php

namespace App\Http\Controllers;

use App\Models\SalesAgent;
use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\CommercialPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commerciaux : liste et fiche, lues dans l’API (api/v1/admin/commercials). Les décisions sur le compte passent par
 * SalesAgentAccessController.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class SalesAgentController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la fiche : segment d’URL => libellé.
     */
    public const TABS = ['' => 'Vue d’ensemble', 'profil-documents' => 'Profil et documents', 'acces-securite' => 'Accès et sécurité'];

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'statut' => 'status', 'zone' => 'commune_id', 'superviseur' => 'supervisor_id', 'periode' => 'period', 'rapide' => 'todo', 'page' => 'page'];

    private const FILTER_KEYS = ['q', 'statut', 'zone', 'superviseur', 'periode', 'rapide', 'tri', 'sens', 'page'];

    private const SORTS = ['commercial' => 'name', 'zone' => 'zone', 'valides' => 'validated', 'taux' => 'validation_rate', 'objectif' => 'objective', 'commissions' => 'commissions', 'statut' => 'status', 'activite' => 'last_activity'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('commercials', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(CommercialPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('SalesAgents/Index', [
            'agents' => [
                'data' => $rows,
                'currentPage' => $meta['current_page'],
                'lastPage' => $meta['last_page'],
                'total' => $meta['total'],
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map($option, array_keys(CommercialPresenter::STATUS_LABELS), CommercialPresenter::STATUS_LABELS),
                'zone' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
                'superviseur' => array_map(fn (array $s): array => $option($s['id'], $s['full_name']), $meta['supervisors']),
                'periode' => [$option('semaine', 'Actifs cette semaine'), $option('mois', 'Actifs ce mois'), $option('inactifs', 'Inactifs depuis plus de 30 jours')],
            ],
            'kpis' => [
                'total' => $meta['counts']['total'],
                'active' => $meta['counts']['statuses']['actif'],
                'recruited' => $meta['counts']['workers'],
                'payable' => $meta['counts']['unpaid'],
            ],
            'todo' => ['objective' => $meta['counts']['todo']['objectifs'], 'followUp' => $meta['counts']['todo']['relances'], 'earnings' => $meta['counts']['todo']['gains'], 'pending' => $meta['counts']['unpaid']],
            'can' => $this->can($request),
        ]);
    }

    /**
     * Export CSV de l’API (commerciaux filtrés, ou sélection `ids=…`).
     */
    public function export(Request $request): HttpResponse
    {
        $ids = array_values(array_filter(explode(',', (string) $request->query('ids')), fn (string $id) => Str::isUuid($id)));
        $response = $this->api->get('commercials/export', [...$this->apiQuery($this->filters($request)), ...($ids ? ['ids' => $ids] : [])]);

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="commerciaux.csv"',
        ]);
    }

    public function show(Request $request, string $commercial, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = $this->api->get("commercials/{$commercial}")->json('data');
        $agent = CommercialPresenter::agent($data);
        $base = "/commerciaux/{$commercial}";
        $documents = CommercialPresenter::documents($data);

        return Inertia::render('SalesAgents/Show', [
            'agent' => $agent,
            'tab' => $tab,
            'documents' => $documents,
            'tabCounts' => ['profil-documents' => count(array_filter($documents, fn (array $d) => $d['canDecide']))],
            'access' => $tab === 'acces-securite' ? CommercialPresenter::access($this->api->get("commercials/{$commercial}/access")->json('data')) : null,
            'documentTypes' => Inertia::optional(fn () => app(SalesAgentFormController::class)->documentTypes()),
            'kpis' => CommercialPresenter::kpis($data),
            'banner' => CommercialPresenter::banner($data),
            'can' => $this->can($request),
            'header' => $this->header('Fiche du commercial', '', [['Commerciaux', '/commerciaux'], [$agent['name'], $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Anciennes adresses (slug local `/commerciaux/koffi-yao/gains-paiements`, onglet ou page pas encore disponible) :
     * une fiche de l’API rouvre sa vue d’ensemble ; un ancien slug lance la recherche du commercial dans la liste.
     */
    public function legacy(string $commercial): RedirectResponse
    {
        if (Str::isUuid($commercial)) {
            Inertia::flash('toast', 'Cet onglet n’est pas encore disponible : voici la vue d’ensemble du commercial.');

            return to_route('sales-agents.show', $commercial);
        }

        if ($commercial === 'classement') {
            Inertia::flash('toast', 'Cette page n’est pas encore disponible.');

            return to_route('sales-agents.index');
        }

        $name = SalesAgent::query()->where('slug', $commercial)->first()?->name ?? Str::of($commercial)->replace('-', ' ')->title()->value();
        Inertia::flash('toast', 'Ce lien renvoie vers une ancienne fiche : voici la recherche correspondante.');

        return to_route('sales-agents.index', ['q' => $name]);
    }

    /**
     * @return array{create: bool, update: bool, terminate: bool, export: bool, documents: bool}
     */
    private function can(Request $request): array
    {
        return ['create' => $this->allowed($request, 'commercials.create'), 'update' => $this->allowed($request, 'commercials.update'), 'terminate' => $this->allowed($request, 'commercials.delete'), 'export' => $this->allowed($request, 'commercials.export'), 'documents' => $this->allowed($request, 'documents.validate')];
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_filter($request->only(self::FILTER_KEYS), fn ($value) => is_string($value) && $value !== '');
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

        if ($sort = self::SORTS[$filters['tri'] ?? ''] ?? null) {
            $query['sort'] = $sort;
            $query['direction'] = ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        return $query;
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
