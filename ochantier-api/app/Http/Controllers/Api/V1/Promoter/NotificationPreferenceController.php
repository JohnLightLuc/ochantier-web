<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\NotificationPreferencesRequest;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Notifications » (Profil › Préférences) : canaux et types d’alertes.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->forUser($request->user())]);
    }

    public function update(NotificationPreferencesRequest $request): JsonResponse
    {
        $this->preferences->update($request->user(), $request->validated('channels', []), $request->validated('alerts', []));

        return response()->json(['message' => 'Préférences enregistrées', 'data' => $this->preferences->forUser($request->user())]);
    }
}
