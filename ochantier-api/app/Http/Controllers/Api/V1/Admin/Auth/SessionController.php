<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Requests\V1\Auth\RefreshTokenRequest;
use App\Http\Resources\V1\Admin\AdminResource;
use App\Http\Resources\V1\AuthSessionResource;
use App\Models\Admin;
use App\Models\AuthSession;
use App\Support\Admin\AdminAudit;
use App\Support\ApiResponse;
use App\Support\Auth\JwtAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Compte connecté et cycle de vie des sessions du Back Office : rafraîchissement, déconnexion, sessions ouvertes.
 */
class SessionController extends AdminAuthController
{
    /**
     * Administrateur connecté, avec ses rôles et permissions.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(AdminResource::make($request->user()->load('roles')));
    }

    /**
     * Échange le jeton de rafraîchissement contre une nouvelle paire de jetons (le précédent devient invalide).
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        if ($this->ipPolicy->blocks($request->ip())) {
            return $this->refused('Connexion impossible depuis ce réseau. Contactez un super administrateur.', 'ip_not_allowed');
        }

        $tokens = $this->sessions->refresh($request->input('refresh_token'), $request->ip());

        if (! $tokens) {
            return response()->json(['message' => 'Session expirée : reconnectez-vous.'], 401);
        }

        /** @var Admin $admin */
        $admin = $tokens->session->authenticatable;

        return $this->signedIn($tokens, $admin);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sessions->revoke(JwtAuthenticator::session($request), 'logout');

        AdminAudit::record($request->user(), 'auth', 'logout', $request);

        return ApiResponse::success(message: 'Vous êtes déconnecté.');
    }

    /**
     * Sessions ouvertes du compte, de la plus récemment active à la plus ancienne.
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
     * Ferme une autre session du compte.
     */
    public function destroy(Request $request, AuthSession $session): JsonResponse
    {
        abort_unless($session->authenticatable_type === 'admin' && $session->authenticatable_id === $request->user()->getKey(), 404);

        $this->sessions->revoke($session, 'revoked_by_admin');

        AdminAudit::record($request->user(), 'auth', 'session_revoked', $request, $session);

        return ApiResponse::success(message: 'Session fermée');
    }
}
