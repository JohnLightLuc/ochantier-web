<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AppProfile;
use App\Enums\InvitationStatus;
use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AccountResource;
use App\Models\Accounts\PromoterAccount;
use App\Models\Accounts\SalesAgentAccount;
use App\Models\Accounts\WorkerAccount;
use App\Support\OneTimePassword;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Connexion par numéro de téléphone : envoi d’un code SMS, puis échange du code contre un jeton d’accès.
 */
class OtpController extends Controller
{
    public function __construct(public OneTimePassword $otp) {}

    /**
     * Envoie un code si un compte existe pour ce numéro. La réponse est identique dans tous les cas
     * pour ne pas révéler les numéros inscrits.
     */
    public function store(Request $request): JsonResponse
    {
        [$profile, $digits] = $this->credentials($request);

        $account = $profile->findByPhone($digits);

        if ($account && ! $account->signInRefusal()) {
            $this->otp->send($profile, $digits);
        }

        return response()->json([
            'message' => 'Si un compte est associé à ce numéro, un code vient d’être envoyé par SMS.',
            'phone' => Phone::mask($digits),
            'expires_in' => $this->otp->lifetime() * 60,
        ], 202);
    }

    /**
     * Vérifie le code et délivre un jeton Sanctum limité à l’application du profil.
     */
    public function verify(Request $request): JsonResponse
    {
        [$profile, $digits] = $this->credentials($request, [
            'code' => ['required', 'digits:6'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $account = $profile->findByPhone($digits);

        if (! $account || ! $this->otp->verify($profile, $digits, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré.']);
        }

        if ($refusal = $account->signInRefusal()) {
            return response()->json(['message' => $refusal], 403);
        }

        $this->recordSignIn($account, $request);

        return response()->json([
            'token' => $account->createToken($request->string('device_name'), [$profile->value])->plainTextToken,
            'token_type' => 'Bearer',
            'profile' => $profile->value,
            'account' => AccountResource::make($account),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: AppProfile, 1: string}
     */
    private function credentials(Request $request, array $rules = []): array
    {
        $request->validate([
            'profile' => ['required', Rule::enum(AppProfile::class)],
            'phone' => ['required', 'string', 'max:20'],
            ...$rules,
        ]);

        $digits = Phone::digits($request->string('phone'));

        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['phone' => 'Numéro à 10 chiffres attendu.']);
        }

        return [AppProfile::from($request->string('profile')), $digits];
    }

    /**
     * Dernière activité, et activation du compte commercial à la première connexion.
     */
    private function recordSignIn(WorkerAccount|PromoterAccount|SalesAgentAccount $account, Request $request): void
    {
        if ($account instanceof PromoterAccount) {
            $account->update(['last_active_at' => now()]);

            return;
        }

        if ($account instanceof SalesAgentAccount) {
            if ($account->status !== SalesAgentStatus::Active) {
                $account->update([
                    'status' => SalesAgentStatus::Active,
                    'invitation_status' => InvitationStatus::Activated,
                    'access' => [...($account->access ?? []), 'activated_at' => today()->format('Y-m-d')],
                ]);
                $account->logActivity(SalesAgentActivityCategory::Activation, 'a activé son accès', null, SalesAgentStatus::Active->label(), null, $account->origin(), $account->name);
            }
            $account->update(['last_activity_on' => today()]);
            $account->logActivity(SalesAgentActivityCategory::Login, 's’est connecté', null, null, $request->string('device_name'), $account->origin(), $account->name);
        }
    }
}
