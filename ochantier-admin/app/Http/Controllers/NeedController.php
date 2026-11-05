<?php

namespace App\Http\Controllers;

use App\Models\Need;
use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\OfferPresenter;
use App\Support\OfferTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Besoins : liste et fiche, lues dans l’API (api/v1/admin/offers), une ligne par offre du promoteur. Les décisions
 * passent par NeedDecisionController.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class NeedController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'statut' => 'status', 'metier' => 'trade_id', 'localisation' => 'commune_id', 'type' => 'offer_type', 'urgence' => 'urgency', 'rapide' => 'todo', 'chantier' => 'site_id', 'page' => 'page'];

    private const SORTS = ['reference' => 'reference', 'demarrage' => 'start', 'limite' => 'deadline', 'couverture' => 'coverage', 'statut' => 'status'];

    private const FILTER_KEYS = ['q', 'statut', 'metier', 'localisation', 'type', 'urgence', 'rapide', 'chantier', 'tri', 'sens', 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('offers', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(OfferPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Needs/Index', [
            'offers' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map($option, array_keys(OfferPresenter::STATUS_LABELS), OfferPresenter::STATUS_LABELS),
                'metier' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['trades']),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
                'type' => [$option('ponctuelle', 'Ponctuelle'), $option('temporaire', 'Temporaire'), $option('contractuelle', 'Contractuelle')],
                'urgence' => [$option('basse', 'Basse'), $option('normale', 'Normale'), $option('haute', 'Haute'), $option('critique', 'Critique')],
            ],
            'kpis' => ['total' => $meta['counts']['total'], 'open' => $meta['counts']['statuses']['soumise'] + $meta['counts']['statuses']['en_controle'] + $meta['counts']['statuses']['publiee'], 'filled' => $meta['counts']['statuses']['pourvue'], 'suspended' => $meta['counts']['statuses']['suspendue']],
            'todo' => $meta['counts']['todo'],
            'can' => $this->can($request),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('offers/export', $this->apiQuery($this->filters($request)));

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="besoins.csv"',
        ]);
    }

    public function show(Request $request, string $need, ?string $tab = null): Response
    {
        $tab ??= '';
        $data = $this->api->get("offers/{$need}")->json('data');
        $view = OfferPresenter::offer($data);
        $base = "/besoins/{$need}";

        return Inertia::render('Needs/Show', [
            'offer' => $view,
            'tab' => $tab,
            'relations' => isset(OfferTabs::LABELS[$tab]) ? app(OfferTabs::class)->load($need, $tab, $request) : null,
            'kpis' => OfferPresenter::kpis($data),
            'banner' => OfferPresenter::banner($data),
            'can' => $this->can($request),
            'header' => $this->header('Fiche du besoin', '', [['Besoins', '/besoins'], [$view['reference'], $tab ? $base : null], ...($tab ? [[OfferTabs::LABELS[$tab]]] : [])]),
        ]);
    }

    /**
     * Anciennes adresses (slug local, onglet retiré) : une fiche de l’API rouvre sa vue d’ensemble ; un ancien slug lance
     * la recherche du besoin dans la liste.
     */
    public function legacy(string $need): RedirectResponse
    {
        if (Str::isUuid($need)) {
            Inertia::flash('toast', 'Cet onglet n’existe plus : voici la fiche du besoin.');

            return to_route('needs.show', $need);
        }

        if ($need === 'nouveau-besoin') {
            Inertia::flash('toast', 'Les besoins sont créés par les promoteurs dans leur application.');

            return to_route('needs.index');
        }

        $reference = Need::query()->where('slug', $need)->first()?->reference ?? Str::of($need)->replace('-', ' ')->value();
        Inertia::flash('toast', 'Ce lien renvoie vers une ancienne fiche : voici la recherche correspondante.');

        return to_route('needs.index', ['q' => $reference]);
    }

    /**
     * @return array{update: bool, validate: bool, export: bool}
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('offers.update'), 'validate' => $allowed('offers.validate'), 'export' => $allowed('offers.export')];
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
}
