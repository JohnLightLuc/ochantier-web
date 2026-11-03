<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\PromoterPresenter;
use App\Support\PromoterTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promoteurs : liste et fiche, lues dans l’API (api/v1/admin/promoters).
 *
 * Les paramètres d’URL de la liste restent en français (q, statut, localisation, type, inscription, rapide, tri,
 * sens, page) et sont traduits pour l’API.
 */
class PromoterController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglets de la fiche : vue générale et KYC, puis les onglets en lecture seule de PromoterTabs.
     */
    private const TABS = ['' => 'Vue générale', 'kyc' => 'Entreprise & KYC', ...PromoterTabs::LABELS];

    /**
     * Clé d’URL => paramètre de l’API.
     */
    private const QUERY = ['q' => 'search', 'statut' => 'status', 'localisation' => 'commune_id', 'type' => 'kind', 'inscription' => 'registered', 'rapide' => 'todo', 'tri' => 'sort', 'sens' => 'direction', 'page' => 'page'];

    private const SORTS = ['promoteur' => 'name', 'inscription' => 'registered_at', 'statut' => 'status'];

    private const STATUSES = ['brouillon' => 'Brouillon', 'soumis' => 'Soumis', 'en_controle' => 'En contrôle', 'a_corriger' => 'À corriger', 'valide' => 'Validé', 'refuse' => 'Refusé', 'suspendu' => 'Suspendu'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $response = $this->api->get('promoters', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(PromoterPresenter::row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Promoters/Index', [
            'promoters' => [
                'data' => $rows,
                'currentPage' => $meta['current_page'],
                'lastPage' => $meta['last_page'],
                'total' => $meta['total'],
                'from' => $from,
                'to' => $from ? $from + count($rows) - 1 : 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map(fn (string $value, string $label): array => compact('value', 'label'), array_keys(self::STATUSES), self::STATUSES),
                'localisation' => array_map(fn (array $c): array => ['value' => $c['id'], 'label' => $c['name']], $meta['communes']),
                'type' => [['value' => 'particulier', 'label' => 'Particulier'], ['value' => 'entreprise', 'label' => 'Entreprise'], ['value' => 'technicien', 'label' => 'Technicien']],
                'inscription' => [['value' => '30j', 'label' => '30 derniers jours'], ...array_map(fn (int $y): array => ['value' => (string) $y, 'label' => (string) $y], range((int) now()->format('Y'), 2025))],
            ],
            'kpis' => [
                'total' => $meta['counts']['total'],
                'active' => $meta['counts']['statuses']['valide'],
                'pending' => $meta['counts']['todo']['a_verifier'],
                'suspended' => $meta['counts']['statuses']['suspendu'],
            ],
            'todo' => $meta['counts']['todo'],
            'can' => ['create' => $this->allowed($request, 'promoters.create')],
        ]);
    }

    /**
     * Export CSV de l’API (promoteurs filtrés, ou sélection `ids=…`).
     */
    public function export(Request $request): HttpResponse
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $ids = array_values(array_filter(explode(',', (string) $request->query('ids'))));
        $response = $this->api->get('promoters/export', [...$this->apiQuery($filters), ...($ids ? ['ids' => $ids] : [])]);

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="promoteurs.csv"',
        ]);
    }

    /**
     * Anciennes adresses de fiche : slug local (`/promoteurs/sicogi/paiements/…`) ou onglet pas encore disponible
     * (`/promoteurs/{uuid}/chantiers`). Une fiche de l’API rouvre sa vue générale ; un ancien slug lance la recherche
     * du promoteur dans la liste, au lieu d’une page introuvable.
     */
    public function legacy(string $promoter): RedirectResponse
    {
        if (Str::isUuid($promoter)) {
            Inertia::flash('toast', 'Cet onglet n’est pas encore disponible : voici la vue générale du promoteur.');

            return to_route('promoters.show', $promoter);
        }

        $name = Promoter::query()->where('slug', $promoter)->value('name') ?? Str::of($promoter)->replace('-', ' ')->title()->value();
        Inertia::flash('toast', 'Ce lien renvoie vers une ancienne fiche : voici la recherche correspondante.');

        return to_route('promoters.index', ['q' => $name]);
    }

    /**
     * Fiche : vue générale, onglet KYC (`item` ouvre l’aperçu d’une pièce) ou onglet en lecture seule (`relations`).
     */
    public function show(Request $request, string $promoter, ?string $tab = null, ?string $item = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $data = $this->api->get("promoters/{$promoter}")->json('data');
        $view = PromoterPresenter::promoter($data);
        $documents = PromoterPresenter::documents($data);
        $base = "/promoteurs/{$promoter}";
        $document = $item && $tab === 'kyc' ? collect($documents)->firstWhere('id', $item) : null;

        $crumbs = [['Promoteurs', '/promoteurs'], [$view['name'], $tab === '' ? null : $base]];
        $crumbs[] = [self::TABS[$tab]];

        return Inertia::render('Promoters/Show', [
            'promoter' => $view,
            'tab' => $tab,
            'kpis' => PromoterPresenter::kpis($data),
            'tabCounts' => ['kyc' => collect($documents)->whereIn('status.value', ['en_verification', 'manquant', 'incomplet', 'refuse', 'expire'])->where('required', true)->count()],
            'documents' => $documents,
            'alerts' => $tab === '' ? $this->alerts($view, $documents, $base) : [],
            'drawer' => $document ? ['kind' => 'document', 'document' => $document] : null,
            'header' => $this->header('Détail du promoteur', '', $crumbs),
            'can' => ['update' => $this->allowed($request, 'promoters.update'), 'addDocument' => $this->allowed($request, 'documents.validate')],
            'documentTypes' => Inertia::optional(fn () => $this->documentTypes($data)),
            'relations' => isset(PromoterTabs::LABELS[$tab]) ? app(PromoterTabs::class)->load($promoter, $tab, $request) : null,
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function apiQuery(array $filters): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $key === 'tri' ? (self::SORTS[$value] ?? 'registered_at') : $value;
        }

        return $query;
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }

    /**
     * Types de pièces que l’administrateur peut ajouter (celles de l’entreprise pour un compte entreprise seulement).
     *
     * @param  array<string, mixed>  $promoter
     * @return list<array<string, mixed>>
     */
    private function documentTypes(array $promoter): array
    {
        $company = ($promoter['kind']['value'] ?? null) === 'entreprise';

        return collect(app(PromoterFormController::class)->formOptions()['document_types'])
            ->filter(fn (array $type) => $company || $type['audience'] !== 'organization')
            ->map(fn (array $type) => [
                'value' => $type['code'],
                'label' => $type['label'],
                'hasBackSide' => $type['has_back_side'],
                'isIdentity' => $type['is_identity'],
                'accept' => implode(',', $type['allowed_mime'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Alertes de la vue générale : dossier à vérifier, pièces manquantes, rejetées ou expirées, litiges ouverts.
     *
     * @param  array<string, mixed>  $promoter
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function alerts(array $promoter, array $documents, string $base): array
    {
        $alerts = [];

        if ($promoter['review']['canDecide']) {
            $alerts[] = $promoter['review']['canApprove']
                ? ['tone' => 'info', 'icon' => 'verified', 'title' => 'Dossier prêt à valider', 'text' => 'toutes les pièces obligatoires sont vérifiées', 'cta' => 'Voir le dossier', 'href' => "{$base}/kyc"]
                : ['tone' => 'warning', 'icon' => 'fact_check', 'title' => 'Dossier à vérifier', 'text' => $promoter['review']['approvalRefusal'], 'cta' => 'Contrôler les pièces', 'href' => "{$base}/kyc"];
        }

        foreach ($documents as $d) {
            $alert = match ($d['status']['value']) {
                'expire' => ['tone' => 'danger', 'icon' => 'event_busy', 'title' => $d['label'].' expiré', 'text' => 'depuis le '.$d['expiresAt']],
                'refuse' => ['tone' => 'danger', 'icon' => 'cancel', 'title' => $d['label'].' rejeté', 'text' => $d['rejectionReason'] ?? 'en attente d’une nouvelle version'],
                'manquant', 'incomplet' => ['tone' => 'warning', 'icon' => 'upload_file', 'title' => $d['status']['value'] === 'manquant' ? 'Pièce manquante' : 'Pièce incomplète', 'text' => $d['label'].' · à fournir par le promoteur'],
                default => null,
            };

            if ($alert) {
                $alerts[] = [...$alert, 'cta' => 'Voir', 'href' => "{$base}/kyc"];
            }
        }

        if ($n = $promoter['flags']['open_disputes_count']) {
            $alerts[] = ['tone' => 'warning', 'icon' => 'gavel', 'title' => $n.' litige'.($n > 1 ? 's ouverts' : ' ouvert'), 'text' => 'sur les chantiers du promoteur', 'cta' => 'Voir les litiges', 'href' => '/avis-litiges/litiges'];
        }

        return $alerts;
    }
}
