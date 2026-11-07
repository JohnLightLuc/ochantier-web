<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ContactRequest;
use App\Http\Requests\V1\Auth\VerifyContactRequest;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\PromoterActivity;
use App\Support\Promoter\PromoterTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connexion d’un promoteur sans mot de passe (« Bon retour parmi nous ») : code reçu par SMS ou e-mail.
 */
class PromoterLoginController extends AuthController
{
    /**
     * Envoie un code au compte actif correspondant ; refuse les coordonnées inconnues et les comptes suspendus ou désactivés.
     * Une inscription non terminée compte comme inconnue : elle se reprend depuis l’écran d’inscription.
     */
    public function store(ContactRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $user = User::findByContact(UserType::Promoter, ...$request->contactLookup());

        if ($refusal = $user?->signInRefusal()) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user?->canSignIn()) {
            throw ValidationException::withMessages($channel === MessageChannel::Sms
                ? ['phone_number' => 'Aucun compte n’existe avec ce numéro. Inscrivez-vous.']
                : ['email' => 'Aucun compte n’existe avec cette adresse e-mail. Inscrivez-vous.']);
        }

        $this->ensureCanResend(OtpPurpose::Login, $destination);
        $this->otp->send(OtpPurpose::Login, $channel, $destination, $user);

        return $this->codeSent($request->recipient(), 'Code de connexion envoyé.');
    }

    /**
     * Vérifie le code ; un collaborateur invité rejoint alors le compte qui l’a invité.
     */
    public function verify(VerifyContactRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();
        $user = User::findByContact(UserType::Promoter, ...$request->contactLookup());

        if ($refusal = $user?->signInRefusal()) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user?->canSignIn() || ! $this->otp->verify(OtpPurpose::Login, $destination, $request->input('code'))) {
            $this->recordAttempt($request, $destination, false, $user, 'login_code');

            throw $this->invalidCode();
        }

        $this->recordAttempt($request, $destination, true, $user);
        app(PromoterTeam::class)->acceptInvitations($user);
        app(PromoterActivity::class)->recordLogin($user, $request);

        return $this->signedIn($this->sessions->open($user, DeviceContext::fromRequest($request)), $user);
    }
}
