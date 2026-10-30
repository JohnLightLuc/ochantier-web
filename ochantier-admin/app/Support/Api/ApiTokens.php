<?php

namespace App\Support\Api;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * Jetons de l’API O’CHANTIER et profil de l’administrateur connecté, conservés dans la session du Back Office.
 *
 * Les jetons sont chiffrés avant d’être écrits en session : ils ne sont jamais transmis au navigateur.
 */
class ApiTokens
{
    private const KEY = 'ochantier_api';

    /**
     * Marge, en secondes, avant l’expiration du jeton d’accès : il est rafraîchi un peu avant.
     */
    private const EXPIRY_MARGIN = 30;

    public function __construct(private Session $session) {}

    /**
     * Enregistre la réponse de connexion ou de rafraîchissement de l’API.
     *
     * @param  array{access_token: string, refresh_token: string, expires_in: int, session_id: string, admin: array<string, mixed>}  $data
     */
    public function store(array $data): void
    {
        $this->session->put(self::KEY, [
            'access_token' => Crypt::encryptString($data['access_token']),
            'refresh_token' => Crypt::encryptString($data['refresh_token']),
            'expires_at' => now()->addSeconds(max(0, $data['expires_in'] - self::EXPIRY_MARGIN))->getTimestamp(),
            'session_id' => $data['session_id'],
            'admin' => $data['admin'],
            'checked_at' => now()->getTimestamp(),
        ]);
    }

    public function has(): bool
    {
        return $this->session->has(self::KEY.'.access_token');
    }

    public function accessToken(): ?string
    {
        return $this->decrypt('access_token');
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt('refresh_token');
    }

    public function accessTokenExpired(): bool
    {
        return now()->getTimestamp() >= (int) $this->session->get(self::KEY.'.expires_at', 0);
    }

    /**
     * Identifiant de la session ouverte dans l’API (liste des sessions, fermeture).
     */
    public function sessionId(): ?string
    {
        return $this->session->get(self::KEY.'.session_id');
    }

    /**
     * Profil de l’administrateur renvoyé par l’API.
     *
     * @return array<string, mixed>|null
     */
    public function admin(): ?array
    {
        return $this->session->get(self::KEY.'.admin');
    }

    /**
     * Met à jour le profil après une vérification auprès de l’API.
     *
     * @param  array<string, mixed>  $admin
     */
    public function refreshAdmin(array $admin): void
    {
        $this->session->put(self::KEY.'.admin', $admin);
        $this->session->put(self::KEY.'.checked_at', now()->getTimestamp());
    }

    /**
     * Le profil a-t-il été vérifié auprès de l’API depuis moins de `$seconds` secondes ?
     */
    public function checkedWithin(int $seconds): bool
    {
        return now()->getTimestamp() - (int) $this->session->get(self::KEY.'.checked_at', 0) < $seconds;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    private function decrypt(string $key): ?string
    {
        $value = $this->session->get(self::KEY.'.'.$key);

        return $value ? Crypt::decryptString($value) : null;
    }
}
