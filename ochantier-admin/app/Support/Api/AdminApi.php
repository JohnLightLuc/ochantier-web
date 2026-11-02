<?php

namespace App\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Client de l’API du Back Office (ochantier-api, préfixe api/v1/admin).
 *
 * Les appels authentifiés utilisent le jeton conservé en session ; il est rafraîchi avant son expiration, ou
 * après un refus 401. Si le rafraîchissement échoue, la session est fermée et l’administrateur renvoyé vers
 * l’écran de connexion.
 *
 * Erreurs : 422 → erreurs de formulaire (ValidationException) ; autres refus et pannes → ApiException.
 * L’adresse IP et le navigateur de l’administrateur sont transmis à l’API (journal, restriction par IP).
 */
class AdminApi
{
    /**
     * Durée, en secondes, pendant laquelle une paire de jetons rafraîchie est réutilisée par les requêtes
     * simultanées de la même session (le jeton de rafraîchissement précédent n’est alors plus valable).
     */
    private const REFRESH_REUSE_SECONDS = 30;

    public function __construct(private ApiTokens $tokens) {}

    public function get(string $uri, array $query = []): Response
    {
        return $this->authenticated('GET', $uri, $query);
    }

    public function post(string $uri, array $data = []): Response
    {
        return $this->authenticated('POST', $uri, $data);
    }

    public function put(string $uri, array $data = []): Response
    {
        return $this->authenticated('PUT', $uri, $data);
    }

    public function patch(string $uri, array $data = []): Response
    {
        return $this->authenticated('PATCH', $uri, $data);
    }

    public function delete(string $uri, array $data = []): Response
    {
        return $this->authenticated('DELETE', $uri, $data);
    }

    /**
     * Envoi de fichiers (multipart) : champs simples dans $data, fichiers dans $files.
     *
     * @param  array<string, scalar|null>  $data
     * @param  array<string, UploadedFile>  $files
     */
    public function upload(string $uri, array $data, array $files): Response
    {
        return $this->authenticated('POST', $uri, $data, $files);
    }

    /**
     * Appel sans jeton (écrans de connexion et de mot de passe oublié).
     *
     * @param  array<string, mixed>  $data
     */
    public function guest(string $method, string $uri, array $data = []): Response
    {
        return $this->check($this->send($method, $uri, $data));
    }

    /**
     * Échange le jeton de rafraîchissement contre une nouvelle paire de jetons.
     *
     * @return bool Faux si la session de l’API est expirée ou fermée.
     */
    public function refresh(): bool
    {
        $refreshToken = $this->tokens->refreshToken();

        if (! $refreshToken) {
            return false;
        }

        $key = 'ochantier-api:refresh:'.hash('sha256', $refreshToken);

        $data = Cache::lock($key.':lock', 10)->block(5, function () use ($key, $refreshToken): ?array {
            if ($cached = Cache::get($key)) {
                return Crypt::decrypt($cached);
            }

            $response = $this->send('POST', 'auth/refresh', ['refresh_token' => $refreshToken]);

            if ($response->serverError()) {
                throw ApiException::unavailable();
            }

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('data');
            Cache::put($key, Crypt::encrypt($data), self::REFRESH_REUSE_SECONDS);

            return $data;
        });

        if (! $data) {
            return false;
        }

        $this->tokens->store($data);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     */
    private function authenticated(string $method, string $uri, array $data, array $files = []): Response
    {
        if (! $this->tokens->has()) {
            throw new AuthenticationException;
        }

        if ($this->tokens->accessTokenExpired() && ! $this->refresh()) {
            $this->expire();
        }

        $response = $this->send($method, $uri, $data, $this->tokens->accessToken(), $files);

        if ($response->status() === 401) {
            if (! $this->refresh()) {
                $this->expire();
            }

            $response = $this->send($method, $uri, $data, $this->tokens->accessToken(), $files);

            if ($response->status() === 401) {
                $this->expire();
            }
        }

        return $this->check($response);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     */
    private function send(string $method, string $uri, array $data, ?string $token = null, array $files = []): Response
    {
        try {
            if ($files !== []) {
                $http = $this->http($token);

                foreach ($files as $name => $file) {
                    $http->attach($name, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), ['Content-Type' => $file->getMimeType()]);
                }

                return $http->post(ltrim($uri, '/'), array_filter($data, fn ($value) => $value !== null && $value !== ''));
            }

            return $this->http($token)->send($method, ltrim($uri, '/'), $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw ApiException::unavailable();
        }
    }

    private function http(?string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.ochantier_api.url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.ochantier_api.timeout'))
            ->withHeaders([
                'Accept-Language' => 'fr',
                'User-Agent' => request()->userAgent() ?: 'OCHANTIER-Admin',
                'X-Forwarded-For' => request()->ip(),
            ])
            ->when($token, fn (PendingRequest $http) => $http->withToken($token));
    }

    private function check(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 422) {
            throw ValidationException::withMessages($response->json('errors') ?: ['api' => $response->json('message')]);
        }

        throw ApiException::fromResponse($response);
    }

    /**
     * Session de l’API expirée ou fermée : l’administrateur doit se reconnecter.
     */
    private function expire(): never
    {
        $this->tokens->forget();
        request()->session()->flash('status', 'Votre session a expiré. Reconnectez-vous.');

        throw new AuthenticationException('Session expirée : reconnectez-vous.');
    }
}
