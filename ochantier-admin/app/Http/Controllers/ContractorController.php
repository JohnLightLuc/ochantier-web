<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\ContractorPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrepreneurs / Techniciens (O’CHANTIER PRO) : liste et fiche, lues dans l’API (api/v1/admin/contractors). Les
 * dossiers KYB/KYC sont déposés depuis l’application Pro ; le Back Office les contrôle (ContractorReviewController).
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class ContractorController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la fiche (clé d’URL => libellé).
     */
    public const TABS = ['' => 'Vue d’ensemble', 'pieces' => 'Pièces justificatives', 'reponses' => 'Réponses aux opportunités', 'historique' => 'Historique'];

    /**
     * Onglets de la liste (clé d’URL => états de l’API regroupés).
     */
    public const LIST_TABS = [
        'a-verifier' => ['À vérifier', 'pending_actions', ['en_verification']],
        'verifies' => ['Vérifiés', 'verified', ['verifie']],
        'rejetes' => ['Rejetés & suspendus', 'block', ['rejete', 'suspendu']],
        'brouillons' => ['Brouillons & à corriger', 'edit_note', ['brouillon', 'a_corriger']],
    ];

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'type' => 'kind', 'metier' => 'trade', 'zone' => 'zone_city_id', 'page' => 'page'];

    private const FILTER_KEYS = ['q', 'type', 'metier', 'zone', 'onglet', 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(self::FILTER_KEYS), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach (array_intersect_key($filters, self::QUERY) as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        if ($tab = self::LIST_TABS[$filters['onglet'] ?? ''] ?? null) {
            $query['state'] = implode(',', $tab[2]);
        }

        $response = $this->api->get('contractors', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(ContractorPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $states = $meta['states'];
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Contractors/Index', [
            'contractors' => [
                'data' => $rows,
                'currentPage' => $meta['current_page'],
                'lastPage' => $meta['last_page'],
                'total' => $meta['total'],
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'type' => [$option('entreprise', 'Entreprise (KYB)'), $option('independant', 'Indépendant (KYC)')],
                'metier' => array_map(fn (array $t): array => $option($t['code'], $t['label']), $meta['trades']),
                'zone' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['zones']),
            ],
            'tabs' => [
                ['key' => '', 'label' => 'Tous', 'icon' => 'groups', 'count' => array_sum($states)],
                ...array_map(fn (string $key, array $tab): array => ['key' => $key, 'label' => $tab[0], 'icon' => $tab[1], 'count' => array_sum(array_intersect_key($states, array_flip($tab[2])))], array_keys(self::LIST_TABS), self::LIST_TABS),
            ],
            'kpis' => [
                'verified' => $states['verifie'] ?? 0,
                'total' => array_sum($states),
                'toReview' => $states['en_verification'] ?? 0,
                'corrections' => $states['a_corriger'] ?? 0,
            ],
            'can' => ['validate' => $this->allowed($request, 'contractors.validate'), 'update' => $this->allowed($request, 'contractors.update')],
        ]);
    }

    /**
     * Fiche : vue d’ensemble, pièces justificatives (visionneuse et décisions), réponses aux opportunités ou historique.
     */
    public function show(Request $request, string $contractor, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = $this->api->get("contractors/{$contractor}")->json('data');
        $view = ContractorPresenter::contractor($data);
        $documents = ContractorPresenter::documents($data);
        $base = "/entrepreneurs/{$contractor}";

        return Inertia::render('Contractors/Show', [
            'contractor' => $view,
            'tab' => $tab,
            'kpis' => ContractorPresenter::kpis($view, $documents),
            'tabCounts' => ['pieces' => count(array_filter($documents, fn (array $d) => $d['canDecide'] && $d['status']['value'] === 'en_attente'))],
            'documents' => $documents,
            'alerts' => ContractorPresenter::alerts($view, $documents, $base),
            'responses' => $tab === 'reponses' ? array_map(ContractorPresenter::response(...), $this->api->get("contractors/{$contractor}/responses")->json('data')) : null,
            'history' => $tab === 'historique' ? array_map(ContractorPresenter::event(...), $this->api->get("contractors/{$contractor}/history")->json('data')) : null,
            'can' => ['validate' => $this->allowed($request, 'contractors.validate'), 'update' => $this->allowed($request, 'contractors.update'), 'documents' => $this->allowed($request, 'documents.validate')],
            'header' => $this->header('Fiche du professionnel', '', [['Entrepreneurs / Techniciens', '/entrepreneurs'], [$view['name'], $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
