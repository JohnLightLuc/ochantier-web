<?php

namespace App\Support\Auth;

use App\Models\Admin;
use App\Models\AuthSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sessions du Back Office : mêmes jetons que les applications mobiles (JWT court et jeton de rafraîchissement à
 * usage unique), avec la politique des comptes administratifs (config auth.admin).
 *
 * Une session se ferme après `idle_minutes` sans activité, et au plus tard `session_hours` après la connexion :
 * le rafraîchissement ne prolonge pas cette échéance. Au-delà de `max_sessions`, les plus anciennes sont fermées.
 */
class AdminSessions
{
    public function __construct(public AccessTokens $accessTokens) {}

    public function open(Admin $admin, DeviceContext $device): IssuedTokens
    {
        return DB::transaction(function () use ($admin, $device): IssuedTokens {
            $this->closeOldestSessions($admin);

            $session = new AuthSession([
                'authenticatable_type' => $admin->getMorphClass(),
                'authenticatable_id' => $admin->getKey(),
                'abilities' => ['admin'],
                'device_id' => $device->deviceId,
                'device_name' => $device->deviceName,
                'platform' => $device->platform ?? 'web',
                'app_name' => 'backoffice',
                'app_version' => $device->appVersion,
                'ip_address' => $device->ipAddress,
                'user_agent' => $device->userAgent,
                'mfa_verified_at' => $admin->requiresMfa() ? now() : null,
                'last_activity_at' => now(),
                'expires_at' => now()->addHours((int) config('auth.admin.session_hours')),
            ]);

            $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $device->ipAddress])->save();

            return $this->issue($session);
        });
    }

    /**
     * Échange un jeton de rafraîchissement contre une nouvelle paire, ou null s’il est inconnu, déjà utilisé, si la
     * session a expiré, est inactive depuis trop longtemps ou si le compte ne peut plus se connecter.
     */
    public function refresh(string $refreshToken, ?string $ipAddress = null): ?IssuedTokens
    {
        return DB::transaction(function () use ($refreshToken, $ipAddress): ?IssuedTokens {
            $session = AuthSession::query()
                ->where('authenticatable_type', 'admin')
                ->where('refresh_token_hash', AuthSessions::hash($refreshToken))
                ->lockForUpdate()
                ->first();

            if (! $session || ! $this->isUsable($session)) {
                return null;
            }

            $session->fill(['ip_address' => $ipAddress ?? $session->ip_address, 'last_activity_at' => now()]);

            return $this->issue($session);
        });
    }

    /**
     * Session active, utilisée récemment, dont le compte peut toujours se connecter. Une session inactive depuis trop
     * longtemps est fermée.
     */
    public function isUsable(AuthSession $session): bool
    {
        if (! AuthSessions::isActive($session)) {
            return false;
        }

        if ($session->last_activity_at->addMinutes((int) config('auth.admin.idle_minutes'))->isPast()) {
            $this->revoke($session, 'idle_timeout');

            return false;
        }

        $admin = $session->authenticatable;

        return $admin instanceof Admin && $admin->canSignIn();
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        if ($session->revoked_at === null) {
            $session->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        }
    }

    /**
     * Ferme toutes les sessions actives d’un administrateur, sauf éventuellement la session courante.
     */
    public function revokeAll(Admin $admin, string $reason, ?AuthSession $except = null): void
    {
        AuthSession::query()
            ->whereMorphedTo('authenticatable', $admin)
            ->whereNull('revoked_at')
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /**
     * Ferme les sessions les plus anciennes pour que la nouvelle reste dans la limite de sessions simultanées.
     */
    private function closeOldestSessions(Admin $admin): void
    {
        $keep = max(0, (int) config('auth.admin.max_sessions') - 1);

        $stale = AuthSession::query()
            ->whereMorphedTo('authenticatable', $admin)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('last_activity_at')
            ->pluck('id')
            ->slice($keep);

        if ($stale->isNotEmpty()) {
            AuthSession::query()->whereKey($stale)->update(['revoked_at' => now(), 'revoked_reason' => 'session_limit']);
        }
    }

    private function issue(AuthSession $session): IssuedTokens
    {
        $jti = (string) Str::uuid();
        $refreshToken = Str::random(64);

        $session->fill([
            'access_token_hash' => AuthSessions::hash($jti),
            'refresh_token_hash' => AuthSessions::hash($refreshToken),
        ])->save();

        return new IssuedTokens(
            session: $session,
            accessToken: $this->accessTokens->issue($session, $jti),
            expiresIn: $this->accessTokens->ttl(),
            refreshToken: $refreshToken,
            refreshExpiresAt: $session->expires_at,
        );
    }
}
