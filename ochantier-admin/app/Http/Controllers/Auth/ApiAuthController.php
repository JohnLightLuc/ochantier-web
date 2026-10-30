<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Api\AdminApi;
use App\Support\Api\ApiException;
use App\Support\Api\ApiTokens;
use App\Support\Api\BrowserDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Outils communs aux écrans d’authentification, qui délèguent la vérification des comptes à l’API O’CHANTIER.
 */
abstract class ApiAuthController extends Controller
{
    /**
     * Cookie identifiant durablement ce navigateur auprès de l’API (liste des sessions ouvertes).
     */
    protected const DEVICE_COOKIE = 'och_device';

    /**
     * Cookie conservant l’adresse e-mail (« Se souvenir de moi »).
     */
    protected const EMAIL_COOKIE = 'och_email';

    public function __construct(protected AdminApi $api, protected ApiTokens $tokens) {}

    /**
     * Appareil décrit à l’API lors de la connexion.
     *
     * @return array{device_name: string, device_id: string}
     */
    protected function device(Request $request): array
    {
        $deviceId = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($deviceId) || ! Str::isUuid($deviceId)) {
            $deviceId = (string) Str::uuid();
            Cookie::queue(self::DEVICE_COOKIE, $deviceId, 60 * 24 * 365 * 5);
        }

        return ['device_name' => BrowserDevice::describe($request->userAgent()), 'device_id' => $deviceId];
    }

    /**
     * Exécute l’appel et présente un refus de l’API (compte verrouillé, attente avant renvoi…) comme une erreur
     * du champ `$field`.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function failAs(string $field, callable $call): mixed
    {
        try {
            return $call();
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /**
     * Ouvre la session du Back Office avec les jetons remis par l’API.
     *
     * @param  array{access_token: string, refresh_token: string, expires_in: int, session_id: string, admin: array<string, mixed>}  $data
     */
    protected function signIn(Request $request, array $data): RedirectResponse
    {
        $request->session()->forget('login.pending');
        $request->session()->regenerate();
        $this->tokens->store($data);

        return redirect()->intended(route('dashboard'));
    }
}
