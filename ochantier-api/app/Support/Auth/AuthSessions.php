<?php

namespace App\Support\Auth;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ouverture, rafraîchissement et révocation des sessions des applications mobiles.
 *
 * Seules les empreintes SHA-256 des jetons sont stockées. Le jeton de rafraîchissement est à usage unique :
 * chaque rafraîchissement le remplace, et le jeton d’accès précédent cesse d’être accepté.
 */
class AuthSessions
{
    public function __construct(public AccessTokens $accessTokens) {}

    public function open(User $user, DeviceContext $device): IssuedTokens
    {
        $session = new AuthSession([
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->getKey(),
            'abilities' => [$user->user_type->value],
            'device_id' => $device->deviceId,
            'device_name' => $device->deviceName,
            'platform' => $device->platform,
            'app_name' => self::appName($user),
            'app_version' => $device->appVersion,
            'ip_address' => $device->ipAddress,
            'user_agent' => $device->userAgent,
            'last_activity_at' => now(),
        ]);

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issue($session);
    }

    /**
     * Échange un jeton de rafraîchissement contre une nouvelle paire, ou null s’il est inconnu, déjà utilisé
     * ou si la session a expiré ou été révoquée.
     */
    public function refresh(string $refreshToken, ?string $ipAddress = null): ?IssuedTokens
    {
        return DB::transaction(function () use ($refreshToken, $ipAddress): ?IssuedTokens {
            $session = AuthSession::query()
                ->where('refresh_token_hash', self::hash($refreshToken))
                ->lockForUpdate()
                ->first();

            if (! $session || ! self::isActive($session) || ! self::ownerCanSignIn($session)) {
                return null;
            }

            $session->fill(['ip_address' => $ipAddress ?? $session->ip_address, 'last_activity_at' => now()]);

            return $this->issue($session);
        });
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        if ($session->revoked_at === null) {
            $session->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        }
    }

    /**
     * Révoque toutes les sessions actives d’un utilisateur, sauf éventuellement la session courante.
     */
    public function revokeAll(User $user, string $reason, ?AuthSession $except = null): void
    {
        AuthSession::query()
            ->whereMorphedTo('authenticatable', $user)
            ->whereNull('revoked_at')
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public static function isActive(AuthSession $session): bool
    {
        return $session->revoked_at === null && $session->expires_at->isFuture();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Nom de l’application enregistré sur la session (promoteur, commercial, ouvrier).
     */
    public static function appName(User $user): string
    {
        return match ($user->user_type->value) {
            'promoter' => 'promoteur',
            'commercial' => 'commercial',
            default => 'ouvrier',
        };
    }

    private static function ownerCanSignIn(AuthSession $session): bool
    {
        $owner = $session->authenticatable;

        return $owner instanceof User && $owner->canSignIn();
    }

    private function issue(AuthSession $session): IssuedTokens
    {
        $jti = (string) Str::uuid();
        $refreshToken = Str::random(64);
        $refreshExpiresAt = now()->addMinutes((int) config('jwt.refresh_ttl'));

        $session->fill([
            'access_token_hash' => self::hash($jti),
            'refresh_token_hash' => self::hash($refreshToken),
            'expires_at' => $refreshExpiresAt,
        ])->save();

        return new IssuedTokens(
            session: $session,
            accessToken: $this->accessTokens->issue($session, $jti),
            expiresIn: $this->accessTokens->ttl(),
            refreshToken: $refreshToken,
            refreshExpiresAt: $refreshExpiresAt,
        );
    }
}
