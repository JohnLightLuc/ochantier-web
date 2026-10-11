<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Écran de connexion.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'step' => 'login',
        ]);
    }

    /**
     * Vérifie les identifiants puis passe à l’étape de vérification en deux étapes.
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

        $user = Auth::getProvider()->retrieveByCredentials(['email' => $credentials['email']]);

        if (! $user || ! Auth::getProvider()->validateCredentials($user, ['password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'email' => 'Adresse e-mail ou mot de passe incorrect.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => $user->status === 'Suspendu' ? 'Ce compte est suspendu. Contactez un administrateur.' : 'Activez d’abord votre compte avec le lien reçu par e-mail.',
            ]);
        }

        $request->session()->put('login.pending', [
            'id' => $user->getAuthIdentifier(),
            'remember' => (bool) ($credentials['remember'] ?? false),
        ]);

        return to_route('login.otp');
    }

    /**
     * Ferme la session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
