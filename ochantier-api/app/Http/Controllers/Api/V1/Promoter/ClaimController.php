<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\ClaimRequest;
use App\Models\Claim;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mes réclamations » : dépôt et suivi. Elles appartiennent au compte, pas à l’utilisateur qui les dépose.
 */
class ClaimController extends Controller
{
    public function __construct(private PromoterClaims $claims) {}

    public function index(Request $request): JsonResponse
    {
        $profile = ProfileController::profile($request);

        return ApiResponse::success($this->claims->list($profile), meta: $this->claims->options($profile));
    }

    public function store(ClaimRequest $request): JsonResponse
    {
        $claim = $this->claims->file(ProfileController::profile($request), $request->validated());

        return ApiResponse::success($this->claims->row($claim->load('site')), 'Réclamation '.$claim->reference.' enregistrée. Nous revenons vers vous rapidement.', 201);
    }

    public function show(Request $request, string $claimId): JsonResponse
    {
        abort_unless(str($claimId)->isUuid(), 404);
        $claim = Claim::query()->whereMorphedTo('claimant', ProfileController::profile($request))->with('site')->findOrFail($claimId);

        return ApiResponse::success($this->claims->row($claim));
    }
}
