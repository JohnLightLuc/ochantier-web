<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vérification en deux étapes : code à 6 chiffres envoyé par e-mail par l’API.
 */
class OtpController extends ApiAuthController
{
    /**
     * Saisie du code à 6 chiffres.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get('login.pending');

        if (! $pending) {
            return to_route('login');
        }

        return Inertia::render('Auth/Login', [
            'step' => 'otp',
            'maskedEmail' => $pending['destination'],
            'resendIn' => max(0, $pending['resend_at'] - now()->getTimestamp()),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Valide le code auprès de l’API et ouvre la session.
     */
    public function store(Request $request): RedirectResponse
    {
        $email = $request->session()->get('login.pending.email');

        if (! $email) {
            return to_route('login');
        }

        $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'Le code doit contenir 6 chiffres.',
            'code.digits' => 'Le code doit contenir 6 chiffres.',
        ]);

        $response = $this->failAs('code', fn () => $this->api->guest('POST', 'auth/login/verify', [
            'email' => $email,
            'code' => $request->input('code'),
            ...$this->device($request),
        ]));

        return $this->signIn($request, $response->json('data'));
    }

    /**
     * Renvoie un nouveau code (« Renvoyer le code »).
     */
    public function resend(Request $request): RedirectResponse
    {
        $email = $request->session()->get('login.pending.email');

        if (! $email) {
            return to_route('login');
        }

        $response = $this->failAs('code', fn () => $this->api->guest('POST', 'auth/login/resend', ['email' => $email]));

        $request->session()->put('login.pending.destination', $response->json('data.destination'));
        $request->session()->put('login.pending.resend_at', now()->addSeconds((int) $response->json('data.resend_in'))->getTimestamp());

        return to_route('login.otp')->with('status', $response->json('message'));
    }
}
