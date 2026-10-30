<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mot de passe oublié : l’API envoie un lien de réinitialisation valable 30 minutes.
 */
class PasswordResetLinkController extends ApiAuthController
{
    /**
     * Formulaire de demande, ou confirmation d’envoi.
     */
    public function create(Request $request): Response
    {
        $sentTo = $request->session()->get('reset.sent_to');

        return Inertia::render('Auth/Login', [
            'step' => $sentTo ? 'sent' : 'forgot',
            'sentTo' => $sentTo,
        ]);
    }

    /**
     * Demande le lien de réinitialisation. La réponse est la même que l’adresse corresponde à un compte ou non.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
        ]);

        $this->failAs('email', fn () => $this->api->guest('POST', 'auth/password/forgot', ['email' => $validated['email']]));

        return to_route('password.request')->with('reset.sent_to', $validated['email']);
    }
}
