<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\DeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Jetons de notification push des appareils du compte connecté.
 */
class DeviceController extends Controller
{
    /**
     * Enregistre (ou rattache au compte connecté) le jeton push de l’appareil.
     */
    public function store(DeviceTokenRequest $request): Response
    {
        DeviceToken::query()->updateOrCreate(['push_token' => $request->input('push_token')], [
            'owner_type' => $request->user()->getMorphClass(),
            'owner_id' => $request->user()->getKey(),
            'platform' => $request->input('platform'),
            'device_id' => $request->input('device_id'),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $token): Response
    {
        $request->user()->deviceTokens()
            ->where('push_token', $token)
            ->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
