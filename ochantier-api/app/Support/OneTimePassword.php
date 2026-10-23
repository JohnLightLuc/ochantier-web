<?php

namespace App\Support;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Sms\SmsGateway;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Codes à usage unique (OTP_LENGTH chiffres, 6 par défaut) envoyés par SMS ou e-mail : activation, connexion,
 * réinitialisation du mot de passe, changement de numéro.
 */
class OneTimePassword
{
    public function __construct(public SmsGateway $sms) {}

    /**
     * Secondes à attendre avant de pouvoir redemander un code pour cette destination.
     */
    public function secondsBeforeResend(OtpPurpose $purpose, string $destination): int
    {
        $last = OtpCode::query()
            ->where('purpose', $purpose)
            ->where('destination', $destination)
            ->latest()
            ->first();

        if (! $last) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($last->created_at->addSeconds($this->resendAfter()), false)));
    }

    /**
     * Invalide les codes précédents et envoie un nouveau code.
     */
    public function send(OtpPurpose $purpose, MessageChannel $channel, string $destination, ?User $user = null): void
    {
        OtpCode::query()
            ->where('purpose', $purpose)
            ->where('destination', $destination)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $code = $this->testCode() ?? str_pad((string) random_int(0, 10 ** self::length() - 1), self::length(), '0', STR_PAD_LEFT);

        OtpCode::create([
            'authenticatable_type' => 'user',
            'authenticatable_id' => $user?->getKey(),
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'max_attempts' => $this->maxAttempts(),
            'expires_at' => now()->addMinutes($this->lifetime()),
        ]);

        $message = "O’CHANTIER : votre code {$this->purposeLabel($purpose)} est {$code}. Il expire dans {$this->lifetime()} minutes. Ne le communiquez à personne.";

        if ($channel === MessageChannel::Email) {
            Mail::raw($message, fn ($mail) => $mail->to($destination)->subject("Votre code O’CHANTIER : {$code}"));

            return;
        }

        $this->sms->send($destination, $message);
    }

    /**
     * Vérifie le code et le consomme s’il est correct. Chaque essai incorrect est compté :
     * au-delà du nombre d’essais autorisés, le code n’est plus utilisable.
     */
    public function verify(OtpPurpose $purpose, string $destination, string $code): bool
    {
        $otp = OtpCode::query()
            ->where('purpose', $purpose)
            ->where('destination', $destination)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts')
            ->latest()
            ->first();

        if (! $otp) {
            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }

    /**
     * Nombre de chiffres d’un code, borné de 4 à 10 ; 6 si OTP_LENGTH est vide.
     */
    public static function length(): int
    {
        return max(4, min(10, (int) config('services.otp.length') ?: 6));
    }

    public function lifetime(): int
    {
        return (int) config('services.otp.lifetime');
    }

    public function resendAfter(): int
    {
        return (int) config('services.otp.resend_after');
    }

    private function maxAttempts(): int
    {
        return (int) config('services.otp.max_attempts');
    }

    /**
     * Code fixe pour les tests et la recette, ignoré en production.
     */
    private function testCode(): ?string
    {
        $code = config('services.otp.test_code');

        return $code && ! app()->isProduction() ? (string) $code : null;
    }

    private function purposeLabel(OtpPurpose $purpose): string
    {
        return match ($purpose) {
            OtpPurpose::Activation => 'd’activation',
            OtpPurpose::Login => 'de connexion',
            OtpPurpose::PasswordReset => 'de réinitialisation',
            OtpPurpose::PhoneChange => 'de confirmation du numéro',
            default => 'de vérification',
        };
    }
}
