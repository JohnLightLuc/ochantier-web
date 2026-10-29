<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Requests\V1\Admin\Auth\EmailRequest;
use App\Http\Requests\V1\Admin\Auth\ResetPasswordRequest;
use App\Http\Requests\V1\Admin\Auth\UpdatePasswordRequest;
use App\Http\Resources\V1\Admin\AdminResource;
use App\Models\PasswordReset;
use App\Support\Admin\AdminAudit;
use App\Support\ApiResponse;
use App\Support\Auth\AuthSessions;
use App\Support\Auth\JwtAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mot de passe des administrateurs : oubli (lien envoyé par e-mail), réinitialisation et changement.
 */
class PasswordController extends AdminAuthController
{
    /**
     * Envoie un lien de réinitialisation si un compte correspond, sans révéler les adresses inscrites.
     */
    public function forgot(EmailRequest $request): JsonResponse
    {
        $admin = $this->findAdmin($request->email());
        $minutes = (int) config('auth.admin.reset_minutes');

        if ($admin?->canSignIn()) {
            $token = Str::random(64);

            DB::transaction(function () use ($admin, $token, $minutes): void {
                PasswordReset::query()->where('authenticatable_type', 'admin')->where('authenticatable_id', $admin->getKey())->whereNull('used_at')->update(['expires_at' => now()]);

                PasswordReset::query()->create([
                    'authenticatable_type' => $admin->getMorphClass(),
                    'authenticatable_id' => $admin->getKey(),
                    'token_hash' => AuthSessions::hash($token),
                    'expires_at' => now()->addMinutes($minutes),
                ]);
            });

            $link = config('auth.admin.reset_url').'?'.http_build_query(['token' => $token, 'email' => $admin->email]);

            Mail::raw(
                "Bonjour {$admin->first_name},\n\nPour choisir un nouveau mot de passe O’CHANTIER ADMIN, ouvrez ce lien (valable {$minutes} minutes) :\n{$link}\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message.",
                fn ($mail) => $mail->to($admin->email)->subject('Réinitialisation de votre mot de passe O’CHANTIER ADMIN'),
            );
        }

        return ApiResponse::success(
            ['expires_in' => $minutes * 60],
            'Si un compte est associé à cette adresse, un lien de réinitialisation vient de lui être envoyé.',
            202,
        );
    }

    /**
     * Remplace le mot de passe et ferme toutes les sessions : l’administrateur se reconnecte avec le nouveau.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $admin = $this->findAdmin($request->email());

        $reset = $admin ? PasswordReset::query()
            ->where('authenticatable_type', 'admin')
            ->where('authenticatable_id', $admin->getKey())
            ->where('token_hash', AuthSessions::hash((string) $request->input('token')))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first() : null;

        if (! $reset || ! $admin->canSignIn()) {
            $this->recordAttempt($request, $request->email(), false, $admin, 'reset_token');

            throw ValidationException::withMessages(['token' => 'Ce lien a expiré ou a déjà été utilisé. Faites une nouvelle demande.']);
        }

        DB::transaction(function () use ($admin, $reset, $request): void {
            $reset->update(['used_at' => now()]);

            $admin->forceFill([
                'password_hash' => $request->input('password'),
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
            ])->save();

            $this->sessions->revokeAll($admin, 'password_reset');
        });

        AdminAudit::record($admin, 'auth', 'password_reset', $request);

        return ApiResponse::success(message: 'Mot de passe mis à jour. Connectez-vous avec votre nouveau mot de passe.');
    }

    /**
     * Change le mot de passe (dont le mot de passe provisoire) et ferme les autres sessions.
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $admin = $request->user();

        $admin->forceFill(['password_hash' => $request->input('password'), 'must_change_password' => false])->save();
        $this->sessions->revokeAll($admin, 'password_changed', JwtAuthenticator::session($request));

        AdminAudit::record($admin, 'auth', 'password_changed', $request);

        return ApiResponse::success(AdminResource::make($admin->load('roles')), 'Mot de passe mis à jour');
    }
}
