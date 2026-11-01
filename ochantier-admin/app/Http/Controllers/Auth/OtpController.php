<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vérification en deux étapes : code envoyé par e-mail par l’API, de la longueur qu’elle annonce (`code_length`).
 */
class OtpController extends ApiAuthController
{
    /**
     * Saisie du code.
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
            'codeLength' => $pending['code_length'] ?? 6,
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

        $length = (int) $request->session()->get('login.pending.code_length', 6);
        $message = "Le code doit contenir {$length} chiffres.";

        $request->validate([
            'code' => ['required', "digits:{$length}"],
        ], [
            'code.required' => $message,
            'code.digits' => $message,
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

        if ($length = (int) $response->json('data.code_length')) {
            $request->session()->put('login.pending.code_length', $length);
        }

        return to_route('login.otp')->with('status', $response->json('message'));
    }
}
