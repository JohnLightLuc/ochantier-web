<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Requests\V1\Commercial\UpdateCommercialProfileRequest;
use App\Http\Requests\V1\Commercial\ZoneChangeRequest;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mon profil » : informations, zone d’activité et demande de changement, résumé de l’activité et des préférences.
 */
class AccountController extends Controller
{
    public function __construct(private CommercialAccount $account) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->account->summary(EnsureActiveCommercial::profile($request)));
    }

    /**
     * « Modifier mes informations » / « Adresse e-mail ».
     */
    public function update(UpdateCommercialProfileRequest $request): JsonResponse
    {
        $this->account->update($request->user(), $request->validated());

        return ApiResponse::success($this->account->summary(EnsureActiveCommercial::profile($request)->refresh()), 'Informations mises à jour');
    }

    /**
     * « Demander un changement » de zone d’activité, validé par le responsable.
     */
    public function requestZone(ZoneChangeRequest $request): JsonResponse
    {
        $commercial = EnsureActiveCommercial::profile($request);
        $this->account->requestZoneChange($commercial, $request->validated('commune_ids'));

        return ApiResponse::success($this->account->summary($commercial), 'Demande envoyée à votre responsable', 201);
    }
}
