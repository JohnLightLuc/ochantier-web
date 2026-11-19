<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ContractorLoginRequest;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use App\Support\Contractor\ContractorMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Connexion d’un entrepreneur ou technicien par téléphone ou e-mail et mot de passe (« Se connecter »).
 *
 * Au-delà du nombre d’essais autorisés, le compte est verrouillé. Un compte dont l’inscription n’a pas été terminée
 * reçoit un nouveau code d’activation, à saisir sur l’écran « Vérification » (register/verify).
 */
class ContractorLoginController extends AuthController
{
    public function store(ContractorLoginRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $user = User::findByContact(UserType::Contractor, ...$request->contactLookup());

        if ($user?->isLocked()) {
            return $this->locked($user);
        }

        if ($refusal = $user?->signInRefusal()) {
            return response()->json(['message' => $refusal], 403);
        }

        if (! $user?->password_hash || ! Hash::check($request->input('password'), $user->password_hash)) {
            $user?->registerFailedLogin();
            $this->recordAttempt($request, $destination, false, $user, $user ? 'wrong_password' : 'unknown_account');

            if ($user?->isLocked()) {
                return $this->locked($user);
            }

            throw ValidationException::withMessages(['password' => 'Identifiant ou mot de passe incorrect.']);
        }

        $user->clearFailedLogins();

        if ($user->status === UserStatus::EnAttenteActivation) {
            $this->ensureCanResend(OtpPurpose::Activation, $destination);
            $this->otp->send(OtpPurpose::Activation, $channel, $destination, $user);

            return $this->codeSent($request->recipient(), 'Votre compte n’est pas encore activé : saisissez le code de vérification envoyé.', ['activation_required' => true]);
        }

        $this->recordAttempt($request, $destination, true, $user);
        app(ContractorMembers::class)->acceptInvitations($user);

        return $this->signedIn($this->sessions->open($user, DeviceContext::fromRequest($request)), $user);
    }
}
