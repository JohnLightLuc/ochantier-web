<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\V1\Auth\UpdatePasswordRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\Auth\JwtAuthenticator;
use Illuminate\Http\JsonResponse;

/**
 * Mot de passe des commerciaux : oubli (code SMS), réinitialisation et changement depuis l’application.
 */
class PasswordController extends AuthController
{
    /**
     * Envoie un code de réinitialisation si un compte correspond, sans révéler les numéros inscrits.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber()->e164();
        $user = User::findByContact(UserType::Commercial, $phone);

        if ($user && ! $user->signInRefusal()) {
            $this->ensureCanResend(OtpPurpose::PasswordReset, $phone);
            $this->otp->send(OtpPurpose::PasswordReset, MessageChannel::Sms, $phone, $user);
        }

        return $this->codeSent($request->phoneNumber(), 'Si un compte est associé à ce numéro, un code vient de lui être envoyé.');
    }

    /**
     * Remplace le mot de passe et ferme toutes les sessions : l’utilisateur se reconnecte avec le nouveau.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber()->e164();
        $user = User::findByContact(UserType::Commercial, $phone);

        if (! $user || $user->signInRefusal() || ! $this->otp->verify(OtpPurpose::PasswordReset, $phone, $request->input('code'))) {
            $this->recordAttempt($request, $phone, false, $user, 'reset_code');

            throw $this->invalidCode();
        }

        $user->forceFill([
            'password_hash' => $request->input('password'),
            'must_change_password' => false,
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        $this->sessions->revokeAll($user, 'password_reset');

        return response()->json(['message' => 'Mot de passe mis à jour. Connectez-vous avec votre nouveau mot de passe.']);
    }

    /**
     * Change le mot de passe (« Sécurité et mot de passe ») et ferme les autres sessions.
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password_hash' => $request->input('password'), 'must_change_password' => false])->save();
        $this->sessions->revokeAll($user, 'password_changed', JwtAuthenticator::session($request));

        return response()->json([
            'message' => 'Mot de passe mis à jour',
            'user' => UserResource::make($user->load(['promoterProfile', 'commercialProfile', 'ownedOrganizations'])),
        ]);
    }
}
