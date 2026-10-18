<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Http\Requests\V1\Auth\ChangePhoneRequest;
use App\Http\Resources\V1\UserResource;
use App\Support\Phone;
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
        $phone = Phone::e164($request->input('phone'));

        $this->ensureCanResend(OtpPurpose::PhoneChange, $phone);
        $this->otp->send(OtpPurpose::PhoneChange, MessageChannel::Sms, $phone, $request->user());

        return $this->codeSent(MessageChannel::Sms, $phone, 'Code de confirmation envoyé au nouveau numéro.');
    }

    /**
     * Enregistre le nouveau numéro une fois le code confirmé (« Confirmer le numéro »).
     */
    public function confirmPhoneChange(ChangePhoneRequest $request): UserResource
    {
        $phone = Phone::e164($request->input('phone'));

        if (! $this->otp->verify(OtpPurpose::PhoneChange, $phone, $request->input('code'))) {
            throw $this->invalidCode();
        }

        $request->user()->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();

        return $this->show($request);
    }
}
