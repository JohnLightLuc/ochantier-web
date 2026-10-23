<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\V1\Auth\RefreshTokenRequest;
use App\Http\Resources\V1\AuthSessionResource;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Auth\JwtAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Cycle de vie des sessions : rafraîchissement des jetons, déconnexion, appareils connectés.
 */
class SessionController extends AuthController
{
    /**
     * Échange le jeton de rafraîchissement contre une nouvelle paire de jetons (le précédent devient invalide).
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->sessions->refresh($request->input('refresh_token'), $request->ip());

        if (! $tokens) {
            return response()->json(['message' => 'Session expirée : reconnectez-vous.'], 401);
        }

        /** @var User $user */
        $user = $tokens->session->authenticatable;

        return $this->signedIn($tokens, $user);
    }

    /**
     * Déconnexion de cet appareil : la session est révoquée et l’appareil ne reçoit plus de notifications.
     */
    public function logout(Request $request): JsonResponse
    {
        $session = JwtAuthenticator::session($request);

        $this->sessions->revoke($session, 'logout');

        if ($session->device_id) {
            $request->user()->deviceTokens()
                ->where('device_id', $session->device_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        return ApiResponse::success(message: 'Vous êtes déconnecté.');
    }

    /**
     * Appareils connectés au compte, du plus récemment actif au plus ancien.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = $request->user()->authSessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('last_activity_at')
            ->get();

        return AuthSessionResource::collection($sessions);
    }

    /**
     * Déconnecte un autre appareil du compte.
     */
    public function destroy(Request $request, AuthSession $session): JsonResponse
    {
        abort_unless($session->authenticatable_type === 'user' && $session->authenticatable_id === $request->user()->getKey(), 404);

        $this->sessions->revoke($session, 'revoked_by_user');

        return ApiResponse::success(message: 'Appareil déconnecté');
    }
}
