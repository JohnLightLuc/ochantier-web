<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Requests\V1\Commercial\ChangeCommercialEmailRequest;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialAccount;
use Illuminate\Http\JsonResponse;

/**
 * « Changer d’adresse e-mail » : un code est envoyé à la nouvelle adresse, qui n’est enregistrée qu’une fois
 * le code confirmé (adresse alors vérifiée).
 */
class EmailController extends AuthController
{
    /**
     * Envoie le code de confirmation à la nouvelle adresse.
     */
    public function request(ChangeCommercialEmailRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $this->ensureCanResend(OtpPurpose::EmailChange, $email);
        $this->otp->send(OtpPurpose::EmailChange, MessageChannel::Email, $email, $request->user());

        return $this->codeSent($email, 'Code de confirmation envoyé à la nouvelle adresse e-mail.');
    }

    /**
     * Enregistre la nouvelle adresse une fois le code confirmé.
     */
    public function confirm(ChangeCommercialEmailRequest $request, CommercialAccount $account): JsonResponse
    {
        $email = $request->validated('email');

        if (! $this->otp->verify(OtpPurpose::EmailChange, $email, $request->validated('code'))) {
            throw $this->invalidCode();
        }

        $request->user()->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

        return ApiResponse::success($account->summary(EnsureActiveCommercial::profile($request)->refresh()), 'Adresse e-mail mise à jour');
    }
}
