<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page publique où un commercial choisit son mot de passe depuis le lien reçu. Il se connecte ensuite dans
 * l’application, pas dans le Back Office.
 */
class CommercialActivationController extends ApiAuthController
{
    public function create(Request $request): Response
    {
        $token = (string) $request->query('token');
        $data = null;

        if ($token !== '') {
            try {
                $data = $this->failAs('token', fn () => $this->api->guest('GET', 'auth/commercial/activation', ['token' => $token]))->json('data');
            } catch (ValidationException) {
                // Lien expiré, révoqué ou déjà utilisé : la page l’explique.
            }
        }

        return Inertia::render('Auth/Login', ['step' => 'activation', 'token' => $token, 'firstName' => $data['first_name'] ?? null, 'phone' => $data['phone'] ?? null, 'done' => (bool) session('activated')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed']], [
            'token.required' => 'Ce lien est incomplet.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $this->failAs('password', fn () => $this->api->guest('POST', 'auth/commercial/activation', [...$validated, 'password_confirmation' => $request->input('password_confirmation')]));

        return to_route('commercial-activation')->with('activated', true);
    }
}
