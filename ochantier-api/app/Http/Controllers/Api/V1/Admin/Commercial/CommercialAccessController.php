<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\CommercialDetailResource;
use App\Support\Admin\CommercialAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décisions du Back Office sur le compte d’un commercial : suspension, réactivation, fin de contrat. Chaque réponse
 * renvoie la fiche à jour.
 */
class CommercialAccessController extends Controller
{
    public function __construct(private CommercialAccess $access) {}

    public function suspend(PromoterReasonRequest $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->access->suspend($model, $model->account_status, $request->user(), $request->reason(), $request);

        return $this->fiche($request, $commercial, 'Compte suspendu.');
    }

    public function reactivate(Request $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->access->reactivate($model, $model->account_status, $request->user(), $request);

        return $this->fiche($request, $commercial, 'Compte réactivé.');
    }

    public function terminate(PromoterReasonRequest $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->access->terminate($model, $model->account_status, $request->user(), $request->reason(), $request);

        return $this->fiche($request, $commercial, 'Contrat terminé : le compte est fermé.');
    }

    private function fiche(Request $request, string $commercial, string $message): JsonResponse
    {
        return ApiResponse::success(CommercialDetailResource::make(CommercialController::find($commercial))->resolve($request), $message);
    }
}
