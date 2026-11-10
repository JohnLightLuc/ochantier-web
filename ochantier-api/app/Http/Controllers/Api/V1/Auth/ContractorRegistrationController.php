<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ConsentType;
use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\Auth\ContractorRegisterRequest;
use App\Http\Requests\V1\Auth\VerifyContactRequest;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\Auth\DeviceContext;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d’un entrepreneur ou technicien (« Créer un compte ») : le compte est créé en attente d’activation avec
 * son mot de passe, puis activé par le code reçu par SMS ou e-mail. Le profil (entreprise ou indépendant) est choisi
 * ensuite, au moment de la vérification du dossier.
 */
class ContractorRegistrationController extends AuthController
{
    /**
     * Consentements donnés à l’inscription.
     *
     * @var list<ConsentType>
     */
    private const CONSENTS = [ConsentType::Cgu, ConsentType::Privacy, ConsentType::DataProcessing];

    /**
     * Enregistre le compte et envoie le code d’activation. Une inscription non terminée peut être reprise, avec le
     * nouveau mot de passe saisi.
     */
    public function store(ContractorRegisterRequest $request): JsonResponse
    {
        [$channel, $destination] = $request->contact();
        $existing = User::findByContact(UserType::Contractor, ...$request->contactLookup());

        if ($existing && $existing->status !== UserStatus::EnAttenteActivation) {
            $field = $channel === MessageChannel::Sms ? 'phone_number' : 'email';

            throw ValidationException::withMessages([$field => 'Un compte existe déjà avec ces coordonnées. Connectez-vous.']);
        }

        $this->ensureCanResend(OtpPurpose::Activation, $destination);

        $user = $existing ?? new User([
            'user_type' => UserType::Contractor,
            'status' => UserStatus::EnAttenteActivation,
            ...PhoneNumber::attributes($request->phoneNumber()),
            'email' => $channel === MessageChannel::Email ? $destination : null,
        ]);
        $user->password_hash = $request->input('password');
        $user->save();

        $this->otp->send(OtpPurpose::Activation, $channel, $destination, $user);

        return $this->codeSent($request->recipient(), 'Code de vérification envoyé.');
    }

    /**
     * Active le compte avec le code reçu, enregistre les consentements et ouvre la session.
     */
    public function verify(VerifyContactRequest $request): JsonResponse
    {
        [, $destination] = $request->contact();
        $user = User::findByContact(UserType::Contractor, ...$request->contactLookup());

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

            foreach (self::CONSENTS as $type) {
                $user->consents()->create([
                    'consent_type' => $type,
                    'legal_document_id' => LegalDocument::query()
                        ->where('code', $type->value)
                        ->where('audience', 'all')
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
