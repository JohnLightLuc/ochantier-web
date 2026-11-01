<?php

namespace App\Http\Controllers\Auth;

use App\Support\Api\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends ApiAuthController
{
    /**
     * Écran de connexion.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'step' => 'login',
            'email' => $request->cookie(self::EMAIL_COOKIE),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Vérifie les identifiants auprès de l’API, qui envoie le code de la vérification en deux étapes.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ], [
            'email.required' => 'Saisissez votre adresse e-mail professionnelle.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'password.required' => 'Saisissez votre mot de passe.',
        ]);

        $response = $this->failAs('email', fn () => $this->api->guest('POST', 'auth/login', [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            ...$this->device($request),
        ]));

        Cookie::queue(($credentials['remember'] ?? false)
            ? Cookie::make(self::EMAIL_COOKIE, mb_strtolower($credentials['email']), 60 * 24 * 90)
            : Cookie::forget(self::EMAIL_COOKIE));

        if ($response->json('data.mfa_required')) {
            $request->session()->put('login.pending', [
                'email' => $credentials['email'],
                'destination' => $response->json('data.destination'),
                'resend_at' => now()->addSeconds((int) $response->json('data.resend_in'))->getTimestamp(),
                'code_length' => (int) ($response->json('data.code_length') ?: 6),
            ]);

            return to_route('login.otp');
        }

        return $this->signIn($request, $response->json('data'));
    }

    /**
     * Ferme la session dans l’API puis dans le Back Office.
     */
    public function destroy(Request $request): RedirectResponse
    {
        try {
            $this->api->post('auth/logout');
        } catch (ApiException|AuthenticationException) {
            // Session déjà fermée côté API, ou API indisponible : la session du Back Office est fermée quand même.
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
