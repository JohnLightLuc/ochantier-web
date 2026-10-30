<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Remplacement du mot de passe provisoire, exigé avant d’accéder aux modules.
 */
class PasswordController extends ApiAuthController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'step' => 'change',
        ]);
    }

    /**
     * Enregistre le nouveau mot de passe ; l’API ferme les autres sessions du compte.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ], [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'password.required' => 'Saisissez votre nouveau mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $response = $this->failAs('password', fn () => $this->api->put('auth/password', $request->only('current_password', 'password', 'password_confirmation')));

        $this->tokens->refreshAdmin($response->json('data'));

        return to_route('dashboard');
    }
}
