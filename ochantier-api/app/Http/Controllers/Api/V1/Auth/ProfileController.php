<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compte connecté, quelle que soit l’application.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'profile' => $account::profile()->value,
            'account' => AccountResource::make($account),
        ]);
    }

    /**
     * Révoque le jeton utilisé (déconnexion de cet appareil uniquement).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
