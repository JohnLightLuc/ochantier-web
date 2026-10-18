<?php

namespace App\Support\Auth;

use App\Models\AuthSession;
use Carbon\CarbonInterface;

/**
 * Paire de jetons remise à l’application après une connexion ou un rafraîchissement.
 */
final readonly class IssuedTokens
{
    public function __construct(
        public AuthSession $session,
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public CarbonInterface $refreshExpiresAt,
    ) {}

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_at: string, session_id: string}
     */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_at' => $this->refreshExpiresAt->toIso8601String(),
            'session_id' => $this->session->getKey(),
        ];
    }
}
