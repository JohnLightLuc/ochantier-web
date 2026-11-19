<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\ContractOfferPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offres contractuelles (O’CHANTIER PRO) : liste et fiche, lues dans l’API (api/v1/admin/contract-offers). Les
 * décisions passent par ContractOfferDecisionController.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class ContractOfferController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la liste (clé d’URL => libellé, icône et statuts affichés regroupés).
     */
    public const TABS = [
        'ouvertes' => ['Ouvertes', 'inbox', ['ouverte', 'en_comparaison']],
        'acompte' => ['Acompte en attente', 'hourglass_top', ['acompte_attendu']],
        'attribuees' => ['Attribuées', 'construction', ['attribuee']],
        'cloturees' => ['Clôturées', 'inventory_2', ['expiree', 'suspendue', 'cloturee', 'annulee']],
    ];

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'metier' => 'trade', 'ville' => 'city_id', 'page' => 'page'];

    private const STATUS_LABELS = [
        'ouverte' => 'Ouverte', 'en_comparaison' => 'En comparaison', 'acompte_attendu' => 'Acompte en attente', 'attribuee' => 'Chantier attribué',
        'expiree' => 'Expirée', 'suspendue' => 'Suspendue', 'cloturee' => 'Clôturée', 'annulee' => 'Annulée',
    ];

    private const FILTER_KEYS = ['q', 'metier', 'ville', 'statut', 'onglet', 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(self::FILTER_KEYS), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach (array_intersect_key($filters, self::QUERY) as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        $statuses = isset($filters['statut']) ? [$filters['statut']] : (self::TABS[$filters['onglet'] ?? ''][2] ?? []);

        if ($statuses !== []) {
            $query['status'] = implode(',', $statuses);
        }

        $response = $this->api->get('contract-offers', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $counts = $meta['counts'];
        $rows = array_map(ContractOfferPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');
        $sum = fn (array $keys): int => array_sum(array_intersect_key($counts, array_flip($keys)));

        return Inertia::render('ContractOffers/Index', [
            'offers' => [
                'data' => $rows,
                'currentPage' => $meta['current_page'],
                'lastPage' => $meta['last_page'],
                'total' => $meta['total'],
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'metier' => array_map(fn (array $t): array => $option($t['code'], $t['label']), $meta['trades']),
                'ville' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['cities']),
                'statut' => array_map($option, array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
            ],
            'tabs' => [
                ['key' => '', 'label' => 'Toutes', 'icon' => 'handshake', 'count' => array_sum($counts)],
                ...array_map(fn (string $key, array $tab): array => ['key' => $key, 'label' => $tab[0], 'icon' => $tab[1], 'count' => $sum($tab[2])], array_keys(self::TABS), self::TABS),
            ],
            'kpis' => [
                'open' => $sum(['ouverte', 'en_comparaison']),
                'comparing' => $counts['en_comparaison'] ?? 0,
                'deposit' => $counts['acompte_attendu'] ?? 0,
                'attributed' => $counts['attribuee'] ?? 0,
            ],
            'rates' => ['commission' => str_replace('.', ',', (string) round((float) $meta['rates']['commission_percent'], 2)).' %', 'deposit' => str_replace('.', ',', (string) round((float) $meta['rates']['deposit_percent'], 2)).' %'],
        ]);
    }

    public function show(Request $request, string $offer): Response
    {
        $view = ContractOfferPresenter::offer($this->api->get("contract-offers/{$offer}")->json('data'));

        return Inertia::render('ContractOffers/Show', [
            'offer' => $view,
            'can' => ['update' => $this->allowed($request, 'offers.update'), 'validate' => $this->allowed($request, 'offers.validate')],
            'header' => $this->header('Offre contractuelle', '', [['Offres contractuelles', '/offres-contractuelles'], ["{$view['reference']} · {$view['site']}"]]),
        ]);
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
