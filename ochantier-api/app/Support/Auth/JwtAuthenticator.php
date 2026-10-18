<?php

namespace App\Support\Auth;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Résout l’utilisateur d’une requête à partir de son jeton Bearer (garde `jwt`).
 *
 * Le jeton n’est accepté que si sa session est active et s’il est le dernier émis pour elle. La session courante
 * est exposée dans l’attribut de requête `auth_session` (déconnexion, liste des appareils).
 */
class JwtAuthenticator
{
    /**
     * Intervalle minimal entre deux mises à jour de la dernière activité d’une session, en secondes.
     */
    private const ACTIVITY_INTERVAL = 60;

    public function __construct(public AccessTokens $accessTokens) {}

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        $claims = $token ? $this->accessTokens->decode($token) : null;

        if (! $claims || $claims->typ !== 'user') {
            return null;
        }

        $session = AuthSession::query()->find($claims->sid);

        if (! $session || ! AuthSessions::isActive($session) || ! hash_equals($session->access_token_hash, AuthSessions::hash($claims->jti))) {
            return null;
        }

        $user = $session->authenticatable;

        if (! $user instanceof User || $user->getKey() !== $claims->sub || ! $user->canSignIn()) {
            return null;
        }

        if ($session->last_activity_at->diffInSeconds(now()) >= self::ACTIVITY_INTERVAL) {
            $session->update(['last_activity_at' => now()]);
        }

        $request->attributes->set('auth_session', $session);

        return $user;
    }

    /**
     * Session de la requête authentifiée.
     */
    public static function session(Request $request): ?AuthSession
    {
        return $request->attributes->get('auth_session');
    }
}
