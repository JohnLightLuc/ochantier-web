<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use App\Http\Requests\V1\Admin\Auth\EmailRequest;
use App\Http\Requests\V1\Admin\Auth\LoginRequest;
use App\Http\Requests\V1\Admin\Auth\VerifyLoginRequest;
use App\Models\Admin;
use App\Models\OtpCode;
use App\Support\Admin\AdminAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Connexion au Back Office : e-mail et mot de passe, puis code à usage unique envoyé par e-mail
 * (« Vérification en deux étapes »).
 *
 * Au-delà du nombre d’essais autorisés, le compte est verrouillé. Hors liste blanche d’adresses IP, la connexion est
 * refusée (mode blocage) ou inscrite au journal d’audit (mode alerte).
 */
class LoginController extends AdminAuthController
{
    /**
     * Durée, en minutes, pendant laquelle un code peut être renvoyé après la vérification du mot de passe.
     */
    private const RESEND_WINDOW = 15;

    /**
     * Vérifie le mot de passe et envoie le code, ou ouvre directement la session si aucun code n’est exigé.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $email = $request->email();
        $admin = $this->findAdmin($email);

        if ($this->ipPolicy->blocks($request->ip())) {
            $this->recordAttempt($request, $email, false, $admin, 'ip_not_allowed');

            return $this->refused('Connexion impossible depuis ce réseau. Contactez un super administrateur.', 'ip_not_allowed');
        }

        if ($admin?->isLocked()) {
            return $this->locked($admin);
        }

        if (! $admin?->password_hash || ! Hash::check((string) $request->input('password'), $admin->password_hash)) {
            $admin?->registerFailedLogin();
            $this->recordAttempt($request, $email, false, $admin, $admin ? 'wrong_password' : 'unknown_account');

            if ($admin?->isLocked()) {
                return $this->locked($admin);
            }

            throw ValidationException::withMessages(['password' => 'E-mail ou mot de passe incorrect.']);
        }

        if ($refusal = $admin->signInRefusal()) {
            $this->recordAttempt($request, $email, false, $admin, 'account_'.$admin->status->value);

            return $this->refused($refusal, 'account_unavailable');
        }

        $admin->clearFailedLogins();

        if (! $admin->requiresMfa()) {
            return $this->complete($request, $admin);
        }

        // Code envoyé il y a moins de 45 s : il reste valable, l’administrateur est renvoyé vers sa saisie.
        $wait = $this->otp->secondsBeforeResend(OtpPurpose::Login, $admin->email, 'admin');

        if ($wait > 0) {
            return $this->codeSent($admin->email, 'Un code vous a déjà été envoyé par e-mail.', $wait);
        }

        $this->sendCode($admin);

        return $this->codeSent($admin->email, 'Code de vérification envoyé par e-mail.');
    }

    /**
     * Vérifie le code et ouvre la session (« Vérifier et continuer »).
     */
    public function verify(VerifyLoginRequest $request): JsonResponse
    {
        $email = $request->email();
        $admin = $this->findAdmin($email);

        if ($this->ipPolicy->blocks($request->ip())) {
            return $this->refused('Connexion impossible depuis ce réseau. Contactez un super administrateur.', 'ip_not_allowed');
        }

        if ($admin && ($refusal = $admin->signInRefusal())) {
            return $this->refused($refusal, 'account_unavailable');
        }

        if (! $admin || $admin->isLocked() || ! $this->otp->verify(OtpPurpose::Login, $admin->email, (string) $request->input('code'), 'admin')) {
            $this->recordAttempt($request, $email, false, $admin, 'login_code');

            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré.']);
        }

        return $this->complete($request, $admin);
    }

    /**
     * Renvoie le code (« Renvoyer le code ») : seulement après un mot de passe vérifié récemment.
     */
    public function resend(EmailRequest $request): JsonResponse
    {
        $admin = $this->findAdmin($request->email());

        $pending = $admin && ! $admin->signInRefusal() && OtpCode::query()
            ->where('authenticatable_type', 'admin')
            ->where('authenticatable_id', $admin->getKey())
            ->where('purpose', OtpPurpose::Login)
            ->whereNull('consumed_at')
            ->where('created_at', '>', now()->subMinutes(self::RESEND_WINDOW))
            ->exists();

        if (! $pending) {
            throw ValidationException::withMessages(['email' => 'Reconnectez-vous avec votre mot de passe pour recevoir un nouveau code.']);
        }

        $this->sendCode($admin);

        return $this->codeSent($admin->email, 'Un nouveau code vous a été envoyé.');
    }

    private function sendCode(Admin $admin): void
    {
        $wait = $this->otp->secondsBeforeResend(OtpPurpose::Login, $admin->email, 'admin');

        if ($wait > 0) {
            throw new HttpResponseException(response()->json([
                'message' => "Patientez {$wait} s avant de demander un nouveau code.",
                'retry_after' => $wait,
            ], 429, ['Retry-After' => $wait]));
        }

        $this->otp->send(OtpPurpose::Login, MessageChannel::Email, $admin->email, $admin);
    }

    private function complete(Request $request, Admin $admin): JsonResponse
    {
        $this->recordAttempt($request, $admin->email, true, $admin);
        AdminAudit::record($admin, 'auth', 'login', $request, after: ['device' => $request->input('device_name')]);

        if ($this->ipPolicy->alerts($request->ip())) {
            AdminAudit::record($admin, 'auth', 'login_unknown_ip', $request, after: ['ip_address' => $request->ip()]);
        }

        return $this->signedIn($this->sessions->open($admin, $this->device($request)), $admin);
    }
}
