<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\CommercialActivationRequest;
use App\Support\Admin\CommercialActivation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lien d’accès d’un commercial : vérification du lien et choix du mot de passe (page publique du Back Office).
 */
class CommercialActivationController extends Controller
{
    public function __construct(private CommercialActivation $activation) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->activation->describe((string) $request->query('token')));
    }

    public function store(CommercialActivationRequest $request): JsonResponse
    {
        $this->activation->complete((string) $request->input('token'), (string) $request->input('password'));

        return ApiResponse::success(message: 'Mot de passe enregistré. Connectez-vous à l’application O’CHANTIER Commercial avec votre numéro de téléphone.');
    }
}
