<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réinitialisation du mot de passe depuis le lien reçu par e-mail.
 */
class NewPasswordController extends ApiAuthController
{
    /**
     * Choix du nouveau mot de passe.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $token = $request->query('token');
        $email = $request->query('email');

        if (! is_string($token) || ! is_string($email) || $token === '' || $email === '') {
            return to_route('password.request');
        }

        return Inertia::render('Auth/Login', [
            'step' => 'reset',
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * Enregistre le nouveau mot de passe : toutes les sessions sont fermées et l’administrateur se reconnecte.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed'],
        ], [
            'token.required' => 'Ce lien est incomplet. Faites une nouvelle demande.',
            'email.required' => 'Ce lien est incomplet. Faites une nouvelle demande.',
            'email.email' => 'Ce lien est incomplet. Faites une nouvelle demande.',
            'password.required' => 'Saisissez votre nouveau mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $response = $this->failAs('password', fn () => $this->api->guest('POST', 'auth/password/reset', [
            ...$validated,
            'password_confirmation' => $request->input('password_confirmation'),
        ]));

        return to_route('login')->with('status', $response->json('message'));
    }
}
