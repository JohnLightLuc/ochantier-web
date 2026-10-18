<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Jetons de notification push des appareils.
 */
class DeviceController extends Controller
{
    /**
     * Enregistre (ou rattache au compte connecté) le jeton push de l’appareil.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $account = $request->user();
        $device = Device::query()->firstOrNew(['token' => $data['token']]);
        $device->fill([...$data, 'last_seen_at' => now()])->account()->associate($account)->save();

        return response()->json(['message' => 'Appareil enregistré.'], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $token): Response
    {
        $request->user()->devices()->where('token', $token)->delete();

        return response()->noContent();
    }
}
