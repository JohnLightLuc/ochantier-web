<?php

namespace App\Http\Controllers\Api\V1\Admin\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Contractor\ContractorIndexRequest;
use App\Http\Resources\V1\Admin\ContractorDetailResource;
use App\Http\Resources\V1\Admin\ContractorListResource;
use App\Models\Contractor;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorAccount;
use App\Support\Contractor\ContractorSubscriptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrepreneurs et techniciens du Back Office : liste filtrée (dossiers à vérifier en premier) et fiche.
 */
class ContractorController extends Controller
{
    /**
     * États affichés (onglets du Back Office), dans l’ordre de ContractorAccount::state().
     */
    public const STATES = ['verifie', 'en_verification', 'a_corriger', 'rejete', 'brouillon', 'suspendu'];

    /**
     * Même calcul que ContractorAccount::state(), en SQL pour filtrer et compter.
     */
    private const STATE_SQL = "CASE WHEN status <> 'actif' THEN 'suspendu' WHEN verification_status = 'valide' THEN 'verifie'"
        ." WHEN verification_status IN ('soumis', 'en_controle') THEN 'en_verification' WHEN verification_status = 'a_corriger' THEN 'a_corriger'"
        ." WHEN verification_status = 'refuse' THEN 'rejete' ELSE 'brouillon' END";

    public function __construct(private ContractorSubscriptions $subscriptions) {}

    /**
     * Liste paginée. meta : pagination et nombre de dossiers par statut de vérification.
     */
    public function index(ContractorIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $contractors = Contractor::query()
            ->with(['city', 'trades', 'zones'])
            ->withCount([
                'quoteBids as open_quote_bids_count' => fn (Builder $query) => $query->whereIn('status', ['proposee', 'choisie']),
                'quoteBids',
                'bids as open_offer_bids_count' => fn (Builder $query) => $query->whereIn('status', ['proposee', 'choisie']),
                'bids as offer_bids_count' => fn (Builder $query) => $query->where('status', '!=', 'brouillon'),
            ])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('reference', 'ilike', '%'.$search.'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')
                ->orWhere('phone_number', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
            ->when($filters['verification_status'] ?? null, fn ($query, string $status) => $query->where('verification_status', $status))
            ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['state'] ?? null, fn ($query, string $state) => $query->whereRaw(self::STATE_SQL.' = ?', [$state]))
            ->when($filters['trade'] ?? null, fn ($query, string $trade) => $query->whereHas('trades', fn (Builder $query) => $query->where('code', $trade)))
            ->when($filters['zone_city_id'] ?? null, fn ($query, string $city) => $query->whereHas('zones', fn (Builder $query) => $query->whereKey($city)))
            ->orderByRaw("CASE WHEN verification_status IN ('soumis','en_controle') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $subscriptions = $this->subscriptions->currentFor($contractors->getCollection()->modelKeys());
        $contractors->getCollection()->each(fn (Contractor $contractor) => $contractor->setRelation('currentSubscription', $subscriptions->get($contractor->getKey())));

        $counts = Contractor::query()->selectRaw('verification_status, count(*) as total')->groupBy('verification_status')->pluck('total', 'verification_status');
        $states = Contractor::query()->selectRaw(self::STATE_SQL.' as state, count(*) as total')->groupByRaw(self::STATE_SQL)->pluck('total', 'state');

        return ApiResponse::success(ContractorListResource::collection($contractors->items())->resolve($request), meta: [
            'current_page' => $contractors->currentPage(),
            'last_page' => $contractors->lastPage(),
            'per_page' => $contractors->perPage(),
            'total' => $contractors->total(),
            'counts' => collect(ContractorVerificationStatus::cases())->mapWithKeys(fn (ContractorVerificationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]),
            'states' => collect(self::STATES)->mapWithKeys(fn (string $state) => [$state => (int) ($states[$state] ?? 0)]),
        ]);
    }

    public function show(Request $request, string $contractor): JsonResponse
    {
        return ApiResponse::success(ContractorDetailResource::make(self::find($contractor))->resolve($request));
    }

    public static function find(string $id): Contractor
    {
        abort_unless(str($id)->isUuid(), 404);

        return Contractor::query()->with([...ContractorAccount::WITH, 'verificationReviews.decidedByAdmin'])->findOrFail($id);
    }
}
