<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vérification en deux étapes.
 *
 * Démonstration : aucun code n’est encore envoyé, tout code à 6 chiffres est accepté.
 */
class OtpController extends Controller
{
    /**
     * Saisie du code à 6 chiffres.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return to_route('login');
        }

        return Inertia::render('Auth/Login', [
            'step' => 'otp',
            'maskedEmail' => preg_replace('/^(.).*(@.*)$/u', '$1•••$2', $user->email),
        ]);
    }

    /**
     * Valide le code et ouvre la session.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return to_route('login');
        }

        $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'Le code doit contenir 6 chiffres.',
            'code.digits' => 'Le code doit contenir 6 chiffres.',
        ]);

        $remember = (bool) $request->session()->pull('login.pending.remember', false);
        $request->session()->forget('login.pending');

        $user->update(['last_login_at' => now()]);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.pending.id');

        return $id ? User::find($id) : null;
    }
}
