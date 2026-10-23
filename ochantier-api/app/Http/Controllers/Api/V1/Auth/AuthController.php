<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Auth\AuthSessions;
use App\Support\Auth\IssuedTokens;
use App\Support\OneTimePassword;
use App\Support\PhoneNumber;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Outils communs aux écrans d’authentification : envoi de codes, délai de renvoi, journal des tentatives
 * et réponse de connexion.
 */
abstract class AuthController extends Controller
{
    public function __construct(public OneTimePassword $otp, public AuthSessions $sessions) {}

    /**
     * Refuse une nouvelle demande de code tant que le délai de renvoi n’est pas écoulé (« Renvoyer le code dans 0:45 »).
     */
    protected function ensureCanResend(OtpPurpose $purpose, string $destination): void
    {
        $wait = $this->otp->secondsBeforeResend($purpose, $destination);

        if ($wait > 0) {
            throw new HttpResponseException(response()->json([
                'message' => "Patientez {$wait} s avant de demander un nouveau code.",
                'retry_after' => $wait,
            ], 429, ['Retry-After' => $wait]));
        }
    }

    /**
     * Réponse après l’envoi (ou la non-divulgation de l’envoi) d’un code, au numéro ou à l’e-mail saisi.
     */
    protected function codeSent(PhoneNumber|string $recipient, string $message): JsonResponse
    {
        $bySms = $recipient instanceof PhoneNumber;

        return ApiResponse::success([
            'channel' => ($bySms ? MessageChannel::Sms : MessageChannel::Email)->value,
            'destination' => $bySms ? $recipient->masked() : self::maskEmail($recipient),
            'expires_in' => $this->otp->lifetime() * 60,
            'resend_in' => $this->otp->resendAfter(),
        ], $message, 202);
    }

    /**
     * Session ouverte : jetons et compte connecté.
     */
    protected function signedIn(IssuedTokens $tokens, User $user, int $status = 200): JsonResponse
    {
        $user->load(['promoterProfile', 'commercialProfile', 'ownedOrganizations']);

        return ApiResponse::success([...$tokens->toArray(), 'user' => UserResource::make($user)], status: $status);
    }

    protected function invalidCode(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Code incorrect ou expiré.']);
    }

    protected function recordAttempt(Request $request, string $identifier, bool $success, ?User $user = null, ?string $failureReason = null): void
    {
        LoginAttempt::create([
            'authenticatable_type' => 'user',
            'authenticatable_id' => $user?->getKey(),
            'identifier' => $identifier,
            'success' => $success,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }

    /**
     * Adresse masquée pour les messages : « k•••@exemple.com ».
     */
    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }
}
