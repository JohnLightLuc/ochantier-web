<?php

namespace App\Http\Controllers\Api\V1\Admin\Matching;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Http\Controllers\Api\V1\Admin\Offer\OfferController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Offer\OfferIndexRequest;
use App\Http\Resources\V1\Admin\OfferListResource;
use App\Support\Admin\OfferDirectory;
use App\Support\ApiResponse;
use App\Support\Matching\MatchingRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Matching du Back Office : file des besoins à traiter, lancement du calcul et résultats par besoin.
 */
class MatchingController extends Controller
{
    public function __construct(private OfferDirectory $directory, private MatchingRunner $runner) {}

    /**
     * Besoins ouverts à la recherche (hors offres contractuelles), avec la date du dernier calcul.
     */
    public function queue(OfferIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $offers = $this->directory->query($filters)
            ->whereIn('offers.status', array_map(fn (OfferStatus $status) => $status->value, MatchingRunner::OPEN_OFFERS))
            ->where('offers.offer_type', '!=', OfferType::Contractuelle->value)
            ->selectSub(fn ($query) => $query->selectRaw('max(matching_runs.executed_at)')->from('matching_runs')->join('needs', 'needs.id', '=', 'matching_runs.need_id')->whereColumn('needs.offer_id', 'offers.id'), 'last_run_at')
            ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        $rows = collect($offers->items())->map(fn ($offer) => [...OfferListResource::make($offer)->resolve($request), 'last_run_at' => $offer->last_run_at])->all();

        return ApiResponse::success($rows, meta: ['current_page' => $offers->currentPage(), 'last_page' => $offers->lastPage(), 'per_page' => $offers->perPage(), 'total' => $offers->total(), 'counts' => $this->directory->counts(), ...$this->directory->options()]);
    }

    public function show(Request $request, string $offer): JsonResponse
    {
        return ApiResponse::success($this->payload($request, $offer));
    }

    public function run(Request $request, string $offer): JsonResponse
    {
        $this->runner->run(OfferController::find($offer), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $offer), 'Matching terminé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $id): array
    {
        $offer = OfferController::find($id);

        return ['offer' => OfferListResource::make($offer)->resolve($request), 'run_refusal' => $this->runner->refusal($offer), 'needs' => $this->runner->results($offer)];
    }
}
