<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Http\Requests\V1\Auth\ChangePhoneRequest;
use App\Http\Resources\V1\UserResource;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compte connecté et changement de numéro de téléphone (confirmé par un code envoyé au nouveau numéro).
 */
class ProfileController extends AuthController
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load(['promoterProfile', 'commercialProfile', 'ownedOrganizations']));
    }

    /**
     * Envoie un code de confirmation au nouveau numéro (« Changer de numéro »).
     */
    public function requestPhoneChange(ChangePhoneRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber();

        $this->ensureCanResend(OtpPurpose::PhoneChange, $phone->e164());
        $this->otp->send(OtpPurpose::PhoneChange, MessageChannel::Sms, $phone->e164(), $request->user());

        return $this->codeSent($phone, 'Code de confirmation envoyé au nouveau numéro.');
    }

    /**
     * Enregistre le nouveau numéro une fois le code confirmé (« Confirmer le numéro »).
     */
    public function confirmPhoneChange(ChangePhoneRequest $request): UserResource
    {
        $phone = $request->phoneNumber();

        if (! $this->otp->verify(OtpPurpose::PhoneChange, $phone->e164(), $request->input('code'))) {
            throw $this->invalidCode();
        }

        $request->user()->forceFill([...PhoneNumber::attributes($phone), 'phone_verified_at' => now()])->save();

        return $this->show($request);
    }
}
