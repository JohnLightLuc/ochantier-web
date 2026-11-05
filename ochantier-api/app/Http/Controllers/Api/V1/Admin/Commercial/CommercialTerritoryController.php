<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Enums\CommercialObjectiveIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\CommercialDetailResource;
use App\Models\CommercialProfile;
use App\Models\CommercialZoneRequest;
use App\Support\Admin\CommercialTerritory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Zones, demandes de changement et objectifs mensuels d’un commercial.
 */
class CommercialTerritoryController extends Controller
{
    public function __construct(private CommercialTerritory $territory) {}

    public function requests(string $commercial): JsonResponse
    {
        return ApiResponse::success($this->territory->requests(CommercialController::find($commercial)));
    }

    public function addZone(Request $request, string $commercial): JsonResponse
    {
        $data = $request->validate([
            'commune_id' => ['required', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'is_primary' => ['boolean'],
        ], ['commune_id.required' => 'Choisissez une commune.']);

        $this->territory->addZone($this->editable($commercial), $data['commune_id'], (bool) ($data['is_primary'] ?? false), $request->user(), $request);

        return $this->fiche($request, $commercial, 'Zone attribuée.');
    }

    public function removeZone(Request $request, string $commercial, string $commune): JsonResponse
    {
        $this->territory->removeZone($this->editable($commercial), $commune, $request->user(), $request);

        return $this->fiche($request, $commercial, 'Zone retirée.');
    }

    public function acceptRequest(Request $request, string $commercial, string $zoneRequest): JsonResponse
    {
        $data = $request->validate(['primary_commune_id' => ['nullable', 'uuid'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $model = $this->editable($commercial);

        $this->territory->accept($model, $this->zoneRequest($model, $zoneRequest), $data['primary_commune_id'] ?? null, $data['comment'] ?? null, $request->user(), $request);

        return $this->fiche($request, $commercial, 'Demande acceptée : les zones sont à jour.');
    }

    public function refuseRequest(PromoterReasonRequest $request, string $commercial, string $zoneRequest): JsonResponse
    {
        $model = CommercialController::find($commercial);

        $this->territory->refuse($model, $this->zoneRequest($model, $zoneRequest), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $commercial, 'Demande refusée.');
    }

    public function objectives(string $commercial): JsonResponse
    {
        return ApiResponse::success($this->territory->objectives(CommercialController::find($commercial)), meta: ['indicator' => ['value' => CommercialObjectiveIndicator::OuvriersValides->value, 'label' => 'Ouvriers validés']]);
    }

    public function saveObjective(Request $request, string $commercial): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'target' => ['required', 'integer', 'min:1', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], ['month.required' => 'Choisissez le mois.', 'target.required' => 'Indiquez le nombre d’ouvriers validés visé.', 'target.min' => 'L’objectif doit être d’au moins 1 ouvrier validé.']);

        $this->territory->saveObjective($this->editable($commercial), $data['month'], (int) $data['target'], $data['comment'] ?? null, $request->user(), $request);

        return $this->fiche($request, $commercial, 'Objectif enregistré.');
    }

    private function editable(string $id): CommercialProfile
    {
        $commercial = CommercialController::find($id);

        if ($commercial->account_status === 'termine') {
            throw ValidationException::withMessages(['commercial' => 'Ce contrat est terminé : réactivez-le d’abord.']);
        }

        return $commercial;
    }

    private function zoneRequest(CommercialProfile $commercial, string $id): CommercialZoneRequest
    {
        return $commercial->zoneRequests()->whereKey($id)->firstOrFail();
    }

    private function fiche(Request $request, string $commercial, string $message): JsonResponse
    {
        return ApiResponse::success(CommercialDetailResource::make(CommercialController::find($commercial))->resolve($request), $message);
    }
}
