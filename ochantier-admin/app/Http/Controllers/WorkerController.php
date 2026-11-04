<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\WorkerPresenter;
use App\Support\WorkerTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ouvriers : liste et fiche, lues dans l’API (api/v1/admin/workers). Les dossiers sont transmis par les
 * commerciaux ; le Back Office les contrôle (WorkerStatusController, WorkerDocumentController).
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class WorkerController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la fiche : vue d’ensemble, identité et documents, métiers, puis les onglets en lecture seule de
     * WorkerTabs.
     */
    public const TABS = ['' => 'Vue d’ensemble', 'identite-documents' => 'Identité et documents', 'metiers-competences' => 'Métiers et compétences', ...WorkerTabs::LABELS];

    /**
     * Clé d’URL => paramètre de l’API (les tranches de coût et de note sont traduites à part).
     */
    private const QUERY = ['q' => 'search', 'metier' => 'trade_id', 'niveau' => 'level', 'disponibilite' => 'availability', 'statut' => 'status', 'localisation' => 'commune_id', 'page' => 'page'];

    private const FILTER_KEYS = ['q', 'metier', 'niveau', 'disponibilite', 'statut', 'localisation', 'cout', 'note', 'rapide', 'tri', 'sens', 'page'];

    private const SORTS = ['ouvrier' => 'name', 'experience' => 'experience', 'cout' => 'daily_rate', 'note' => 'rating', 'statut' => 'status'];

    private const QUICK = ['verification' => 'a_verifier', 'corrections' => 'a_corriger', 'pieces' => 'pieces_expirees'];

    private const RATES = ['moins-15000' => [null, 14999, 'Moins de 15 000 FCFA'], '15000-25000' => [15000, 25000, '15 000 à 25 000 FCFA'], 'plus-25000' => [25001, null, 'Plus de 25 000 FCFA']];

    private const RATINGS = ['4.5' => [4.5, null, '4,5 et plus'], '4' => [4, 4.5, '4 à 4,4'], 'moins-4' => [null, 4, 'Moins de 4']];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('workers', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(WorkerPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Workers/Index', [
            'workers' => [
                'data' => $rows,
                'currentPage' => $meta['current_page'],
                'lastPage' => $meta['last_page'],
                'total' => $meta['total'],
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'metier' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['trades']),
                'niveau' => [$option('debutant', 'Débutant (moins de 3 ans)'), $option('confirme', 'Confirmé (3 à 6 ans)'), $option('expert', 'Expert (7 ans et +)')],
                'disponibilite' => [$option('disponible', 'Disponible'), $option('occupe', 'En mission'), $option('indisponible', 'Indisponible')],
                'statut' => array_map($option, array_keys(WorkerPresenter::STATUS_LABELS), WorkerPresenter::STATUS_LABELS),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
                'cout' => array_map(fn (string $key, array $band): array => $option($key, $band[2]), array_keys(self::RATES), self::RATES),
                'note' => array_map(fn (string $key, array $band): array => $option($key, $band[2]), array_keys(self::RATINGS), self::RATINGS),
            ],
            'kpis' => [
                'total' => $meta['counts']['total'],
                'available' => $meta['counts']['availability']['disponible'],
                'onMission' => $meta['counts']['availability']['occupe'],
                'toReview' => $meta['counts']['todo']['a_verifier'],
            ],
            'todo' => ['review' => $meta['counts']['todo']['a_verifier'], 'corrections' => $meta['counts']['todo']['a_corriger'], 'documents' => $meta['counts']['todo']['pieces_expirees']],
            'can' => ['create' => $this->allowed($request, 'workers.create'), 'validate' => $this->allowed($request, 'workers.validate'), 'update' => $this->allowed($request, 'workers.update'), 'export' => $this->allowed($request, 'workers.export')],
        ]);
    }

    /**
     * Export CSV de l’API (ouvriers filtrés, ou sélection `ids=…`).
     */
    public function export(Request $request): HttpResponse
    {
        $ids = array_values(array_filter(explode(',', (string) $request->query('ids')), fn (string $id) => Str::isUuid($id)));
        $response = $this->api->get('workers/export', [...$this->apiQuery($this->filters($request)), ...($ids ? ['ids' => $ids] : [])]);

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="ouvriers.csv"',
        ]);
    }

    /**
     * Fiche : vue d’ensemble, identité et documents, métiers et compétences, ou onglet en lecture seule (`relations`).
     */
    public function show(Request $request, string $worker, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = $this->api->get("workers/{$worker}")->json('data');
        $view = WorkerPresenter::worker($data);
        $documents = WorkerPresenter::documents($data);
        $base = "/ouvriers/{$worker}";

        return Inertia::render('Workers/Show', [
            'worker' => $view,
            'tab' => $tab,
            'kpis' => WorkerPresenter::kpis($data, $documents),
            'tabCounts' => ['identite-documents' => count(array_filter($documents, fn (array $d) => $d['canDecide']))],
            'documents' => $documents,
            'alerts' => WorkerPresenter::alerts($data, $base),
            'correctionFields' => array_map(fn (string $value, string $label): array => compact('value', 'label'), array_keys(WorkerPresenter::CORRECTION_FIELDS), WorkerPresenter::CORRECTION_FIELDS),
            'can' => ['validate' => $this->allowed($request, 'workers.validate'), 'update' => $this->allowed($request, 'workers.update'), 'documents' => $this->allowed($request, 'documents.validate')],
            'documentTypes' => Inertia::optional(fn () => app(WorkerFormController::class)->documentTypes()),
            'relations' => isset(WorkerTabs::LABELS[$tab]) ? app(WorkerTabs::class)->load($worker, $tab, $request) : null,
            'header' => $this->header('Fiche de l’ouvrier', '', [['Ouvriers', '/ouvriers'], [$view['name'], $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Anciennes adresses de fiche (slug local `/ouvriers/kouame-koffi/pointages`, ou onglet pas encore disponible) :
     * une fiche de l’API rouvre sa vue d’ensemble ; un ancien slug lance la recherche de l’ouvrier dans la liste.
     */
    public function legacy(string $worker): RedirectResponse
    {
        if (Str::isUuid($worker)) {
            Inertia::flash('toast', 'Cet onglet n’est pas encore disponible : voici la vue d’ensemble de l’ouvrier.');

            return to_route('workers.show', $worker);
        }

        $name = Worker::query()->where('slug', $worker)->first()?->name ?? Str::of($worker)->replace('-', ' ')->title()->value();
        Inertia::flash('toast', 'Ce lien renvoie vers une ancienne fiche : voici la recherche correspondante.');

        return to_route('workers.index', ['q' => $name]);
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

        if ($band = self::RATES[$filters['cout'] ?? ''] ?? null) {
            $query += array_filter(['daily_rate_min' => $band[0], 'daily_rate_max' => $band[1]], fn ($v) => $v !== null);
        }

        if ($band = self::RATINGS[$filters['note'] ?? ''] ?? null) {
            $query += array_filter(['rating_min' => $band[0], 'rating_max' => $band[1]], fn ($v) => $v !== null);
        }

        if ($todo = self::QUICK[$filters['rapide'] ?? ''] ?? null) {
            $query['todo'] = $todo;
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
