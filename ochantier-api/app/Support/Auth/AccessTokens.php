<?php

namespace App\Support\Auth;

use App\Models\AuthSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Signature et lecture des jetons d’accès JWT.
 *
 * Revendications : iss, sub (utilisateur), sid (session), jti (identifiant du jeton), typ (admin ou user),
 * app (application d’origine), iat, nbf, exp.
 */
class AccessTokens
{
    /**
     * Émet un jeton pour la session ; son jti doit être enregistré (haché) sur la session.
     */
    public function issue(AuthSession $session, string $jti): string
    {
        $now = now()->getTimestamp();

        return JWT::encode([
            'iss' => config('jwt.issuer'),
            'sub' => $session->authenticatable_id,
            'sid' => $session->getKey(),
            'jti' => $jti,
            'typ' => $session->authenticatable_type,
            'app' => $session->app_name,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl(),
        ], $this->secret(), config('jwt.algorithm'));
    }

    /**
     * Revendications d’un jeton valide (signature, émetteur, dates), sinon null.
     */
    public function decode(string $token): ?stdClass
    {
        JWT::$leeway = (int) config('jwt.leeway');
        JWT::$timestamp = now()->getTimestamp();

        try {
            $claims = JWT::decode($token, new Key($this->secret(), config('jwt.algorithm')));
        } catch (Throwable) {
            return null;
        }

        if (($claims->iss ?? null) !== config('jwt.issuer') || ! isset($claims->sid, $claims->jti, $claims->sub)) {
            return null;
        }

        return $claims;
    }

    /**
     * Durée de vie d’un jeton d’accès, en secondes.
     */
    public function ttl(): int
    {
        return (int) config('jwt.access_ttl') * 60;
    }

    private function secret(): string
    {
        return (string) config('jwt.secret') ?: throw new RuntimeException('JWT_SECRET n’est pas défini.');
    }
}
