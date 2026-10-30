<?php

namespace App\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * Garde du Back Office : l’administrateur est connecté tant que l’API accepte les jetons conservés en session.
 *
 * Le profil (rôles, permissions, état du compte) est revérifié auprès de l’API au plus toutes les minutes : un
 * compte suspendu ou une session fermée depuis un autre poste est ainsi déconnecté rapidement. Si l’API est
 * momentanément indisponible, le dernier profil connu est conservé.
 */
class ApiGuard implements Guard
{
    use GuardHelpers;

    private const RECHECK_SECONDS = 60;

    public function __construct(private ApiTokens $tokens, private AdminApi $api) {}

    public function user(): ?Authenticatable
    {
        if ($this->user) {
            return $this->user;
        }

        if (! $this->tokens->has()) {
            return null;
        }

        if (! $this->tokens->checkedWithin(self::RECHECK_SECONDS)) {
            try {
                $this->tokens->refreshAdmin($this->api->get('me')->json('data'));
            } catch (AuthenticationException) {
                return null;
            } catch (ApiException $e) {
                if ($e->getStatusCode() < 500) {
                    $this->tokens->forget();

                    return null;
                }
            }
        }

        $admin = $this->tokens->admin();

        return $this->user = $admin ? AdminUser::fromApi($admin) : null;
    }

    /**
     * Les identifiants sont vérifiés par l’API (écran de connexion), jamais par le garde.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Oublie les jetons de l’API (la session de l’API est fermée par l’écran de déconnexion).
     */
    public function logout(): void
    {
        $this->tokens->forget();
        $this->user = null;
    }
}
