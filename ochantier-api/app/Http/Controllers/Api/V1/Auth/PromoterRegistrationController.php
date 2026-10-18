<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ConsentType;
use App\Enums\MessageChannel;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\OtpPurpose;
use App\Enums\PromoterProfileKind;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\PromoterRegisterRequest;
use App\Http\Requests\V1\Auth\VerifyContactRequest;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d’un promoteur sans mot de passe (« Créer votre compte ») : le compte est créé en attente
 * d’activation, puis activé par le code reçu par SMS ou e-mail.
 */
class PromoterRegistrationController extends AuthController
{
    /**
     * Enregistre le profil choisi et envoie le code d’activation. Une inscription non terminée peut être reprise.
     */
    public function store(PromoterRegisterRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $existing = User::findByContact(UserType::Promoter, ...$request->contactLookup());

        if ($existing && $existing->status !== UserStatus::EnAttenteActivation) {
            $field = $channel === MessageChannel::Sms ? 'phone_number' : 'email';

            throw ValidationException::withMessages([$field => 'Un compte existe déjà avec ces coordonnées. Connectez-vous.']);
        }

        $this->ensureCanResend(OtpPurpose::Activation, $destination);

        $user = DB::transaction(function () use ($request, $existing, $channel, $destination): User {
            $user = $existing ?? User::create([
                'user_type' => UserType::Promoter,
                'status' => UserStatus::EnAttenteActivation,
                ...PhoneNumber::attributes($request->phoneNumber()),
                'email' => $channel === MessageChannel::Email ? $destination : null,
            ]);

            $kind = PromoterProfileKind::from($request->input('profile_kind'));
            $user->promoterProfile()->updateOrCreate([], ['profile_kind' => $kind]);

            $organization = $user->ownedOrganizations()->first();

            if ($kind === PromoterProfileKind::Entreprise) {
                $organization
                    ? $organization->update(['legal_name' => $request->input('company_name')])
                    : $user->ownedOrganizations()->create(['legal_name' => $request->input('company_name')]);
            } else {
                $organization?->delete();
            }

            return $user;
        });

        $this->otp->send(OtpPurpose::Activation, $channel, $destination, $user);

        return $this->codeSent($request->recipient(), 'Code d’activation envoyé.');
    }

    /**
     * Active le compte avec le code reçu, enregistre les consentements et ouvre la session.
     */
    public function verify(VerifyContactRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();
        $user = User::findByContact(UserType::Promoter, ...$request->contactLookup());

        if (! $user || $user->status !== UserStatus::EnAttenteActivation || ! $this->otp->verify(OtpPurpose::Activation, $destination, $request->input('code'))) {
            $this->recordAttempt($request, $destination, false, $user, 'activation_code');

            throw $this->invalidCode();
        }

        $device = DeviceContext::fromRequest($request);

        DB::transaction(function () use ($user, $request, $device): void {
            $user->forceFill([
                'status' => UserStatus::Actif,
                $request->phoneNumber() ? 'phone_verified_at' : 'email_verified_at' => now(),
            ])->save();

            $user->ownedOrganizations()->first()?->members()->firstOrCreate(['user_id' => $user->getKey()], [
                'role' => OrganizationRole::Administrateur,
                'status' => OrganizationMemberStatus::Actif,
                'joined_at' => now(),
            ]);

            foreach ([ConsentType::Cgu, ConsentType::Privacy] as $type) {
                $user->consents()->create([
                    'consent_type' => $type,
                    'legal_document_id' => LegalDocument::query()
                        ->where('code', $type->value)
                        ->whereIn('audience', ['promoter', 'all'])
                        ->where('effective_from', '<=', now())
                        ->latest('effective_from')
                        ->value('id'),
                    'granted' => true,
                    'granted_at' => now(),
                    'recorded_by_type' => 'user',
                    'recorded_by_id' => $user->getKey(),
                    'channel' => 'application',
                    'ip_address' => $device->ipAddress,
                    'device_id' => $device->deviceId,
                ]);
            }
        });

        $this->recordAttempt($request, $destination, true, $user);

        return $this->signedIn($this->sessions->open($user, $device), $user, 201);
    }
}
