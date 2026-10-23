<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\DeviceTokenRequest;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jetons de notification push des appareils du compte connecté.
 */
class DeviceController extends Controller
{
    /**
     * Enregistre (ou rattache au compte connecté) le jeton push de l’appareil.
     */
    public function store(DeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::query()->updateOrCreate(['push_token' => $request->input('push_token')], [
            'owner_type' => $request->user()->getMorphClass(),
            'owner_id' => $request->user()->getKey(),
            'platform' => $request->input('platform'),
            'device_id' => $request->input('device_id'),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        return ApiResponse::success();
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $request->user()->deviceTokens()
            ->where('push_token', $token)
            ->update(['revoked_at' => now()]);

        return ApiResponse::success();
    }
}
