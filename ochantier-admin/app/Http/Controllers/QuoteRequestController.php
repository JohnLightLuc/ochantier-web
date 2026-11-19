<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\ProCommissionPresenter;
use App\Support\QuotePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demandes de devis (O’CHANTIER PRO) : liste et fiche, lues dans l’API (api/v1/admin/quote-requests). Une ligne par
 * corps de métier d’une demande ; les décisions passent par QuoteRequestDecisionController.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class QuoteRequestController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la liste (clé d’URL => libellé, icône et statuts affichés regroupés).
     */
    public const TABS = [
        'ouvertes' => ['Ouvertes', 'inbox', ['ouverte']],
        'en-cours' => ['En cours', 'autorenew', ['visite_programmee', 'report_propose', 'devis_en_preparation', 'devis_envoye']],
        'cloturees' => ['Clôturées', 'inventory_2', ['devis_recu', 'expiree', 'annulee']],
    ];

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'metier' => 'trade', 'ville' => 'city_id', 'page' => 'page'];

    /**
     * Libellés des statuts affichés (filtre « Statut »).
     */
    private const STATUS_LABELS = [
        'ouverte' => 'Ouverte', 'visite_programmee' => 'Visite programmée', 'report_propose' => 'Report de visite proposé', 'devis_en_preparation' => 'Devis en préparation',
        'devis_envoye' => 'Devis envoyé', 'devis_recu' => 'Devis reçu', 'expiree' => 'Expirée', 'annulee' => 'Annulée',
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

        $onLedger = ($filters['onglet'] ?? '') === 'commissions' && $this->allowed($request, 'finance.read');
        $ledger = $onLedger ? ProCommissionPresenter::ledger($this->api->get('pro-commissions', array_filter(['source' => 'demande_devis', 'search' => $filters['q'] ?? null, 'page' => $filters['page'] ?? null, 'per_page' => self::PER_PAGE]))->json()) : null;
        $statuses = isset($filters['statut']) ? [$filters['statut']] : (self::TABS[$filters['onglet'] ?? ''][2] ?? []);

        if ($statuses !== []) {
            $query['status'] = implode(',', $statuses);
        }

        $response = $this->api->get('quote-requests', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $counts = $meta['counts'];
        $rows = array_map(QuotePresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');
        $sum = fn (array $keys): int => array_sum(array_intersect_key($counts, array_flip($keys)));

        return Inertia::render('Quotes/Index', [
            'quotes' => [
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
                'statut' => array_map(fn (string $value): array => $option($value, self::STATUS_LABELS[$value]), array_keys(self::STATUS_LABELS)),
            ],
            'tabs' => [
                ['key' => '', 'label' => 'Toutes', 'icon' => 'request_quote', 'count' => array_sum($counts)],
                ...array_map(fn (string $key, array $tab): array => ['key' => $key, 'label' => $tab[0], 'icon' => $tab[1], 'count' => $sum($tab[2])], array_keys(self::TABS), self::TABS),
                ...($this->allowed($request, 'finance.read') ? [['key' => 'commissions', 'label' => 'Commissions', 'icon' => 'percent', 'count' => $ledger['total'] ?? null]] : []),
            ],
            'ledger' => $ledger,
            'kpis' => [
                'open' => $counts['ouverte'] ?? 0,
                'inProgress' => $sum(self::TABS['en-cours'][2]),
                'toNudge' => $sum(['report_propose', 'devis_envoye']),
                'received' => $counts['devis_recu'] ?? 0,
            ],
        ]);
    }

    public function show(Request $request, string $quote): Response
    {
        $view = QuotePresenter::quote($this->api->get("quote-requests/{$quote}")->json('data'));

        return Inertia::render('Quotes/Show', [
            'quote' => $view,
            'can' => ['update' => $this->allowed($request, 'offers.update'), 'validate' => $this->allowed($request, 'offers.validate')],
            'header' => $this->header('Demande de devis', '', [['Demandes de devis', '/demandes-devis'], ["{$view['reference']} · {$view['trade']}"]]),
        ]);
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
