<?php

namespace App\Http\Controllers\Api\V1\Admin\Contractor;

use App\Http\Controllers\Controller;
use App\Support\Admin\ContractorRelations;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Onglets en lecture seule de la fiche d’un entrepreneur : réponses aux opportunités et historique du dossier.
 */
class ContractorRelationController extends Controller
{
    public function __construct(private ContractorRelations $relations) {}

    public function responses(string $contractor): JsonResponse
    {
        return ApiResponse::success($this->relations->responses(ContractorController::find($contractor)));
    }

    public function history(string $contractor): JsonResponse
    {
        return ApiResponse::success($this->relations->history(ContractorController::find($contractor)));
    }
}
