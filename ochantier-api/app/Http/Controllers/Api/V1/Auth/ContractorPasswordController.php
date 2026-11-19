<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ContactCodeRequest;
use App\Http\Requests\V1\Auth\ContactRequest;
use App\Http\Requests\V1\Auth\ContractorResetPasswordRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * « Récupérer l’accès » d’un entrepreneur : code envoyé par SMS ou e-mail, vérification du code, puis nouveau mot de passe.
 */
class ContractorPasswordController extends AuthController
{
    /**
     * Envoie un code de réinitialisation si un compte actif correspond, sans révéler les comptes inscrits.
     */
    public function forgot(ContactRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $user = $this->account($request->contactLookup());

        if ($user) {
            $this->ensureCanResend(OtpPurpose::PasswordReset, $destination);
            $this->otp->send(OtpPurpose::PasswordReset, $channel, $destination, $user);
        }

        return $this->codeSent($request->recipient(), 'Si un compte correspond à cet identifiant, un code vient de lui être envoyé.');
    }

    /**
     * Vérifie le code saisi avant l’écran « Nouveau mot de passe », sans le consommer.
     */
    public function verify(ContactCodeRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();

        if (! $this->account($request->contactLookup()) || ! $this->otp->check(OtpPurpose::PasswordReset, $destination, $request->input('code'))) {
            throw $this->invalidCode();
        }

        return ApiResponse::success(message: 'Code vérifié. Choisissez votre nouveau mot de passe.');
    }

    /**
     * Remplace le mot de passe et ferme toutes les sessions : l’utilisateur se reconnecte avec le nouveau.
     */
    public function reset(ContractorResetPasswordRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();
        $user = $this->account($request->contactLookup());

        if (! $user || ! $this->otp->verify(OtpPurpose::PasswordReset, $destination, $request->input('code'))) {
            $this->recordAttempt($request, $destination, false, $user, 'reset_code');

            throw $this->invalidCode();
        }

        $user->forceFill(['password_hash' => $request->input('password'), 'failed_login_count' => 0, 'locked_until' => null])->save();
        $this->sessions->revokeAll($user, 'password_reset');

        return ApiResponse::success(message: 'Mot de passe modifié. Connectez-vous avec votre nouveau mot de passe.');
    }

    /**
     * Compte actif correspondant à l’identifiant saisi.
     *
     * @param  array{0: string|null, 1: string|null}  $lookup
     */
    private function account(array $lookup): ?User
    {
        $user = User::findByContact(UserType::Contractor, ...$lookup);

        return $user && $user->status === UserStatus::Actif && ! $user->signInRefusal() ? $user : null;
    }
}
