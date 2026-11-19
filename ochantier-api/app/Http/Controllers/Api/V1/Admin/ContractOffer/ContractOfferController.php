<?php

namespace App\Http\Controllers\Api\V1\Admin\ContractOffer;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\ContractOffer\ContractOfferIndexRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\ContractOfferDetailResource;
use App\Http\Resources\V1\Admin\ContractOfferListResource;
use App\Support\Admin\ContractOfferDirectory;
use App\Support\Admin\ContractOfferOperations;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Offres contractuelles du Back Office (O’CHANTIER PRO) : liste, fiche, prolongation, annulation avant l’acompte et
 * relances. Chaque décision renvoie la fiche à jour.
 */
class ContractOfferController extends Controller
{
    public function __construct(private ContractOfferDirectory $directory, private ContractOfferOperations $operations) {}

    /**
     * Liste paginée. meta : pagination, nombre d’offres par statut affiché, taux X et Y, métiers et villes des filtres.
     */
    public function index(ContractOfferIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $offers = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(ContractOfferListResource::collection($offers->items())->resolve($request), meta: [
            'current_page' => $offers->currentPage(),
            'last_page' => $offers->lastPage(),
            'per_page' => $offers->perPage(),
            'total' => $offers->total(),
            'counts' => $this->directory->counts(),
            'rates' => $this->directory->rates(),
            ...$this->directory->options(),
        ]);
    }

    public function show(Request $request, string $offer): JsonResponse
    {
        return $this->fiche($request, $offer);
    }

    public function extend(Request $request, string $offer): JsonResponse
    {
        $data = $request->validate(['deadline' => ['required', 'date', 'after:now']], ['deadline.required' => 'Indiquez la nouvelle date limite.', 'deadline.after' => 'La date limite doit être dans le futur.']);
        $this->operations->extend($this->directory->find($offer), Carbon::parse($data['deadline'])->endOfDay(), $request->user(), $request);

        return $this->fiche($request, $offer, 'Date limite prolongée.');
    }

    public function cancel(PromoterReasonRequest $request, string $offer): JsonResponse
    {
        $this->operations->cancel($this->directory->find($offer), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $offer, 'Offre contractuelle annulée.');
    }

    public function remind(Request $request, string $offer): JsonResponse
    {
        $data = $request->validate(
            ['target' => ['required', 'in:promoter,contractors'], 'message' => ['nullable', 'string', 'min:5', 'max:500']],
            ['target.required' => 'Choisissez le destinataire de la relance.', 'target.in' => 'Destinataire inconnu.'],
        );
        $sent = $this->operations->remind($this->directory->find($offer), $data['target'], isset($data['message']) ? trim($data['message']) : null, $request->user(), $request);

        return $this->fiche($request, $offer, $data['target'] === 'promoter' ? 'Relance envoyée au promoteur.' : 'Relance envoyée à '.$sent.' professionnel'.($sent > 1 ? 's' : '').'.');
    }

    private function fiche(Request $request, string $offer, ?string $message = null): JsonResponse
    {
        return ApiResponse::success(ContractOfferDetailResource::make($this->directory->find($offer))->resolve($request), $message);
    }
}
