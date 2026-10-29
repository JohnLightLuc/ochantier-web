<?php

namespace App\Support\Auth;

use App\Models\Admin;
use App\Models\AuthSession;
use Illuminate\Http\Request;

/**
 * Résout l’administrateur d’une requête à partir de son jeton Bearer (garde `admin`).
 *
 * Mêmes règles que les applications mobiles (session active, dernier jeton émis), plus la politique du Back Office :
 * session fermée après une période d’inactivité, et adresse IP hors liste blanche refusée en mode blocage.
 */
class AdminAuthenticator
{
    /**
     * Intervalle minimal entre deux mises à jour de la dernière activité d’une session, en secondes.
     */
    private const ACTIVITY_INTERVAL = 60;

    public function __construct(public AccessTokens $accessTokens, public AdminSessions $sessions, public AdminIpPolicy $ipPolicy) {}

    public function __invoke(Request $request): ?Admin
    {
        $token = $request->bearerToken();
        $claims = $token ? $this->accessTokens->decode($token) : null;

        if (! $claims || $claims->typ !== 'admin') {
            return null;
        }

        $session = AuthSession::query()->find($claims->sid);

        if (! $session || $session->authenticatable_type !== 'admin' || ! hash_equals($session->access_token_hash, AuthSessions::hash($claims->jti)) || ! $this->sessions->isUsable($session)) {
            return null;
        }

        $admin = $session->authenticatable;

        if (! $admin instanceof Admin || $admin->getKey() !== $claims->sub || $this->ipPolicy->blocks($request->ip())) {
            return null;
        }

        if ($session->last_activity_at->diffInSeconds(now()) >= self::ACTIVITY_INTERVAL) {
            $session->update(['last_activity_at' => now()]);
        }

        $request->attributes->set('auth_session', $session);

        return $admin;
    }
}
