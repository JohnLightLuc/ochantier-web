<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mot de passe oublié.
 *
 * Démonstration : aucun e-mail n’est encore envoyé.
 */
class PasswordResetLinkController extends Controller
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
     * Enregistre la demande de lien de réinitialisation.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
        ]);

        return to_route('password.request')->with('reset.sent_to', $validated['email']);
    }
}
