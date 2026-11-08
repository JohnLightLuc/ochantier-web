<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Enums\ContractPaymentMode;
use App\Enums\DocumentAudience;
use App\Enums\PricingVariable;
use App\Enums\SiteWorkType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\SiteRequest;
use App\Http\Resources\V1\Promoter\SiteDetailResource;
use App\Http\Resources\V1\Promoter\SiteResource;
use App\Models\ConstructionType;
use App\Models\DocumentType;
use App\Models\Site;
use App\Models\Trade;
use App\Support\ApiResponse;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Chantiers du promoteur connecté (« Mes chantiers », « Nouveau chantier », « Détail chantier »).
 */
class SiteController extends Controller
{
    public const WITH = ['constructionType', 'mainImageMedia', 'commune', 'neighborhood', 'responsibleUser'];

    public function __construct(private SiteManager $sites) {}

    /**
     * Chantiers du plus récemment modifié au plus ancien. Filtres : filter (brouillons, recherche, en_cours,
     * termines), search (titre ou adresse). Le nombre de chantiers par onglet est renvoyé dans meta.counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'filter' => ['nullable', 'in:'.implode(',', array_keys(SiteManager::FILTERS))],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $profile = ProfileController::profile($request);
        $search = fn ($query) => $query->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
            ->where('label', 'ilike', '%'.addcslashes($search, '%_\\').'%')
            ->orWhere('address', 'ilike', '%'.addcslashes($search, '%_\\').'%')));

        $access = app(PromoterAccess::class);
        $mine = fn () => $access->scopeSites($profile->sites(), $request->user());

        $sites = $mine()
            ->with(self::WITH)
            ->tap($search)
            ->when($filters['filter'] ?? null, fn ($query, $filter) => $this->sites->filter($query, $filter))
            ->latest('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $counts = ['tous' => $mine()->tap($search)->count()];

        foreach (array_keys(SiteManager::FILTERS) as $filter) {
            $counts[$filter] = $this->sites->filter($mine()->tap($search), $filter)->count();
        }

        return SiteResource::collection($sites)->additional(['meta' => ['counts' => $counts]]);
    }

    /**
     * Enregistre un brouillon. Avec un client_uuid déjà reçu, renvoie le chantier existant (saisie hors ligne).
     */
    public function store(SiteRequest $request): JsonResponse
    {
        $profile = ProfileController::profile($request);

        if ($uuid = $request->input('client_uuid')) {
            $existing = $profile->sites()->withTrashed()->where('client_uuid', $uuid)->first();

            if ($existing) {
                return SiteDetailResource::make($existing->load(self::WITH))->response()->setStatusCode(200);
            }
        }

        $site = $this->sites->create($profile, $request->user(), $request->validated());

        return SiteDetailResource::make($site->load(self::WITH))
            ->additional(['message' => 'Brouillon enregistré'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Site $site): SiteDetailResource
    {
        return SiteDetailResource::make($site->load(self::WITH));
    }

    public function update(SiteRequest $request, Site $site): SiteDetailResource
    {
        $site = $this->sites->update($site, $request->validated());

        return SiteDetailResource::make($site->load(self::WITH))->additional(['message' => 'Brouillon enregistré']);
    }

    public function destroy(Site $site): JsonResponse
    {
        $this->sites->delete($site);

        return ApiResponse::success(message: 'Brouillon supprimé');
    }

    /**
     * Listes du formulaire « Nouveau chantier » : types de travaux, types de bien, responsables possibles,
     * documents acceptés, métiers avec leur tarif moyen constaté, modes de paiement du forfait, taux d’acompte, corps de
     * métier de la demande de devis et type de pièce du devis.
     */
    public function formOptions(Request $request, PricingRules $pricing): JsonResponse
    {
        $rates = $pricing->referenceRates();

        return ApiResponse::success([
            'trades' => Trade::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (Trade $trade) => [
                'id' => $trade->id,
                'code' => $trade->code,
                'label' => $trade->label,
                'reference_rate' => $rates->get($trade->id)['rate'] ?? null,
            ]),
            'payment_modes' => array_map(fn (ContractPaymentMode $mode) => ['value' => $mode->value, 'label' => $mode->label()], OfferDraft::PAYMENT_MODES),
            'quote_trades' => Trade::query()->where('is_active', true)->where('accepts_quotes', true)->orderBy('sort_order')->get(['id', 'code', 'label']),
            'quote_document_type' => OfferDraft::QUOTE_DOCUMENT,
            'deposit_percent' => $pricing->value(PricingVariable::X),
            'low_rate_percent' => OfferDraft::LOW_RATE_PERCENT,
            'work_types' => array_map(fn (SiteWorkType $type) => ['value' => $type->value, 'label' => $type->label()], SiteWorkType::cases()),
            'property_types' => ConstructionType::query()->where('is_active', true)->orderBy('created_at')->get(['id', 'code', 'label']),
            'responsibles' => $this->sites->responsibles(ProfileController::profile($request), $request->user()),
            'document_types' => DocumentType::query()
                ->where('audience', DocumentAudience::Site)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DocumentType $type) => ['code' => $type->code, 'label' => $type->label, 'allowed_mime' => $type->allowed_mime, 'max_size_kb' => $type->max_size_kb]),
        ]);
    }
}
