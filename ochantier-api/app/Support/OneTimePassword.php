<?php

namespace App\Support;

use App\Enums\AppProfile;
use App\Models\OtpCode;
use App\Support\Sms\SmsGateway;
use Illuminate\Support\Facades\Hash;

/**
 * Codes de connexion à usage unique envoyés par SMS.
 */
class OneTimePassword
{
    public function __construct(public SmsGateway $sms) {}

    /**
     * Invalide les codes précédents et envoie un nouveau code.
     */
    public function send(AppProfile $profile, string $digits): void
    {
        OtpCode::query()->where('profile', $profile)->where('phone', $digits)->whereNull('consumed_at')->delete();

        $code = $this->fixedCode() ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'profile' => $profile,
            'phone' => $digits,
            'code' => $code,
            'expires_at' => now()->addMinutes($this->lifetime()),
        ]);

        $this->sms->send(Phone::format($digits), "O’CHANTIER : votre code de connexion est {$code}. Il expire dans {$this->lifetime()} minutes. Ne le communiquez à personne.");
    }

    /**
     * Vérifie le code et le consomme s’il est correct (chaque essai incorrect est compté).
     */
    public function verify(AppProfile $profile, string $digits, string $code): bool
    {
        $otp = OtpCode::query()->usable()->where('profile', $profile)->where('phone', $digits)->latest('id')->first();

        if (! $otp) {
            return false;
        }

        if (! Hash::check($code, $otp->code)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }

    public function lifetime(): int
    {
        return (int) config('services.otp.lifetime', 10);
    }

    /**
     * Code fixe pour les environnements de développement (jamais en production).
     */
    private function fixedCode(): ?string
    {
        $code = config('services.otp.test_code');

        return $code && ! app()->isProduction() ? (string) $code : null;
    }
}
