<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CommercialProfileStatus;
use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\CommercialLoginRequest;
use App\Http\Requests\V1\Auth\PhoneCodeRequest;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Connexion d’un commercial en deux étapes : numéro et mot de passe, puis code SMS à chaque nouvelle connexion.
 *
 * Le compte est créé par le Back Office avec un mot de passe provisoire ; il devient actif à la première
 * connexion réussie, et le mot de passe doit alors être remplacé.
 */
class CommercialLoginController extends AuthController
{
    /**
     * Vérifie le mot de passe et envoie le code SMS. Au-delà du nombre d’essais autorisés, le compte est verrouillé.
     */
    public function store(CommercialLoginRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber()->e164();
        $user = User::findByContact(UserType::Commercial, $phone);

        if ($user?->isLocked()) {
            return $this->locked($user);
        }

        if ($refusal = $this->refusal($user)) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user?->password_hash || ! Hash::check($request->input('password'), $user->password_hash)) {
            $user?->registerFailedLogin();
            $this->recordAttempt($request, $phone, false, $user, $user ? 'wrong_password' : 'unknown_account');

            if ($user?->isLocked()) {
                return $this->locked($user);
            }

            throw ValidationException::withMessages(['password' => 'Numéro ou mot de passe incorrect.']);
        }

        $user->clearFailedLogins();
        $this->ensureCanResend(OtpPurpose::Login, $phone);
        $this->otp->send(OtpPurpose::Login, MessageChannel::Sms, $phone, $user);

        return $this->codeSent($request->phoneNumber(), 'Code de vérification envoyé par SMS.');
    }

    /**
     * Vérifie le code SMS et ouvre la session (« Vérifier et se connecter »).
     */
    public function verify(PhoneCodeRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber()->e164();
        $user = User::findByContact(UserType::Commercial, $phone);

        if ($refusal = $this->refusal($user)) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user || $user->isLocked() || ! $this->otp->verify(OtpPurpose::Login, $phone, $request->input('code'))) {
            $this->recordAttempt($request, $phone, false, $user, 'login_code');

            throw $this->invalidCode();
        }

        if ($user->status === UserStatus::EnAttenteActivation) {
            $user->forceFill(['status' => UserStatus::Actif, 'phone_verified_at' => now()])->save();
        }

        $this->recordAttempt($request, $phone, true, $user);

        return $this->signedIn($this->sessions->open($user, DeviceContext::fromRequest($request)), $user);
    }

    /**
     * Compte suspendu, désactivé, ou contrat commercial terminé.
     */
    private function refusal(?User $user): ?string
    {
        if ($refusal = $user?->signInRefusal()) {
            return $refusal;
        }

        return in_array($user?->commercialProfile?->status, [CommercialProfileStatus::Suspendu, CommercialProfileStatus::Termine], true)
            ? 'Votre accès commercial n’est plus actif. Contactez votre responsable O’CHANTIER.'
            : null;
    }

    private function locked(User $user): JsonResponse
    {
        return response()->json([
            'message' => 'Trop de tentatives. Réessayez à '.$user->locked_until->timezone('Africa/Abidjan')->format('H:i').'.',
            'locked_until' => $user->locked_until->toIso8601String(),
        ], 423);
    }
}
