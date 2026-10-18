<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ContactRequest;
use App\Http\Requests\V1\Auth\VerifyContactRequest;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use Illuminate\Http\JsonResponse;

/**
 * Connexion d’un promoteur sans mot de passe (« Bon retour parmi nous ») : code reçu par SMS ou e-mail.
 */
class PromoterLoginController extends AuthController
{
    /**
     * Envoie un code si un compte actif correspond. La réponse est identique dans tous les cas
     * pour ne pas révéler les coordonnées inscrites.
     */
    public function store(ContactRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $user = User::findByContact(UserType::Promoter, $request->input('phone'), $request->input('email'));

        if ($user?->canSignIn()) {
            $this->ensureCanResend(OtpPurpose::Login, $destination);
            $this->otp->send(OtpPurpose::Login, $channel, $destination, $user);
        }

        return $this->codeSent($channel, $destination, 'Si un compte est associé à ces coordonnées, un code vient de lui être envoyé.');
    }

    public function verify(VerifyContactRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();
        $user = User::findByContact(UserType::Promoter, $request->input('phone'), $request->input('email'));

        if ($refusal = $user?->signInRefusal()) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user?->canSignIn() || ! $this->otp->verify(OtpPurpose::Login, $destination, $request->input('code'))) {
            $this->recordAttempt($request, $destination, false, $user, 'login_code');

            throw $this->invalidCode();
        }

        $this->recordAttempt($request, $destination, true, $user);

        return $this->signedIn($this->sessions->open($user, DeviceContext::fromRequest($request)), $user);
    }
}
