<?php

namespace App\Http\Controllers\Api\V1\Admin\Rating;

use App\Enums\RatingModerationStatus;
use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\RatingDetailResource;
use App\Http\Resources\V1\Admin\RatingResource;
use App\Models\Rating;
use App\Support\Admin\RatingDirectory;
use App\Support\Admin\RatingModeration;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Avis laissés par les promoteurs : liste, fiche et modération.
 */
class RatingController extends Controller
{
    public function __construct(private RatingDirectory $directory, private RatingModeration $moderation) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in([RatingStatus::Publie->value, RatingStatus::Masque->value])],
            'moderation' => ['nullable', Rule::enum(RatingModerationStatus::class)],
            'scope' => ['nullable', Rule::enum(RatingScope::class)],
            'score' => ['nullable', 'integer', 'between:1,5'],
            'suspected' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->directory->ratings($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(RatingResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->counts(),
        ]);
    }

    public function show(Request $request, string $ratingId): JsonResponse
    {
        return $this->fiche($request, $ratingId);
    }

    public function report(PromoterReasonRequest $request, string $ratingId): JsonResponse
    {
        $this->moderation->report($this->find($ratingId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $ratingId, 'Avis signalé.');
    }

    public function keep(PromoterReasonRequest $request, string $ratingId): JsonResponse
    {
        $this->moderation->keep($this->find($ratingId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $ratingId, 'Avis maintenu.');
    }

    public function hide(PromoterReasonRequest $request, string $ratingId): JsonResponse
    {
        $this->moderation->hide($this->find($ratingId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $ratingId, 'Avis masqué.');
    }

    public function restore(PromoterReasonRequest $request, string $ratingId): JsonResponse
    {
        $this->moderation->restore($this->find($ratingId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $ratingId, 'Avis rétabli.');
    }

    public function suspect(PromoterReasonRequest $request, string $ratingId): JsonResponse
    {
        $request->validate(['suspected' => ['required', 'boolean']]);
        $suspected = $request->boolean('suspected');
        $this->moderation->suspect($this->find($ratingId), $suspected, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $ratingId, $suspected ? 'Avis marqué comme suspect.' : 'Doute levé sur cet avis.');
    }

    private function find(string $id): Rating
    {
        return $this->directory->ratings([])->where('ratings.id', $id)->firstOrFail();
    }

    private function fiche(Request $request, string $id, ?string $message = null): JsonResponse
    {
        return ApiResponse::success((new RatingDetailResource($this->find($id)))->resolve($request), $message);
    }
}
