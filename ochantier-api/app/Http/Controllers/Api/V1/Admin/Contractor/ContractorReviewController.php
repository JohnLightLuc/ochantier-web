<?php

namespace App\Http\Controllers\Api\V1\Admin\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Contractor\ContractorCorrectionsRequest;
use App\Http\Requests\V1\Admin\Contractor\ContractorReasonRequest;
use App\Http\Resources\V1\Admin\ContractorDetailResource;
use App\Models\Contractor;
use App\Support\Admin\ContractorVerification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décisions du Back Office sur le dossier d’un entrepreneur : validation, corrections, refus, suspension et
 * réactivation. Chaque décision renvoie la fiche à jour.
 */
class ContractorReviewController extends Controller
{
    public function __construct(private ContractorVerification $verification) {}

    public function approve(Request $request, string $contractor): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->approve($model, $request->user(), $request);

        return $this->fiche($request, $model, 'Profil vérifié.');
    }

    public function requestCorrections(ContractorCorrectionsRequest $request, string $contractor): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->requestCorrections($model, $request->reason(), $request->documentIds(), $request->user(), $request);

        return $this->fiche($request, $model, 'Corrections demandées à l’entrepreneur.');
    }

    public function reject(ContractorReasonRequest $request, string $contractor): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->reject($model, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $model, 'Dossier refusé.');
    }

    public function approveDocument(Request $request, string $contractor, string $document): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->approveDocument($model, $this->verification->document($model, $document), $request->user(), $request);

        return $this->fiche($request, $model, 'Pièce confirmée.');
    }

    public function rejectDocument(ContractorReasonRequest $request, string $contractor, string $document): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->rejectDocument($model, $this->verification->document($model, $document), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $model, 'Pièce rejetée.');
    }

    public function suspend(ContractorReasonRequest $request, string $contractor): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->suspend($model, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $model, 'Compte suspendu.');
    }

    public function reactivate(Request $request, string $contractor): JsonResponse
    {
        $model = ContractorController::find($contractor);
        $this->verification->reactivate($model, $request->user(), $request);

        return $this->fiche($request, $model, 'Compte réactivé.');
    }

    private function fiche(Request $request, Contractor $contractor, string $message): JsonResponse
    {
        return ApiResponse::success(ContractorDetailResource::make(ContractorController::find($contractor->id))->resolve($request), $message);
    }
}
