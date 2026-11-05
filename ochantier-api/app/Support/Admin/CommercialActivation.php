<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Models\PasswordReset;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\PhoneNumber;
use App\Support\Sms\SmsGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lien d’accès d’un commercial, envoyé par SMS et par e-mail s’il en a un : il y choisit son mot de passe, à la
 * création du compte (activation) ou à la demande du Back Office (réinitialisation). Un seul lien vit à la fois ; le
 * jeton n’est conservé que haché. Le compte devient actif à la première connexion, après le code SMS.
 */
class CommercialActivation
{
    public const VALID_HOURS = 72;

    public function __construct(private SmsGateway $sms, private AuthSessions $sessions) {}

    /**
     * Crée et envoie un nouveau lien ; le précédent cesse de fonctionner. Pour un compte actif, les sessions sont
     * fermées dès l’envoi.
     */
    public function send(CommercialProfile $commercial, string $status, Admin $admin, Request $request): void
    {
        if (! in_array($status, ['en_attente_activation', 'actif'], true)) {
            throw ValidationException::withMessages(['commercial' => 'Ce compte est '.($status === 'termine' ? 'terminé' : 'suspendu').' : réactivez-le d’abord.']);
        }

        $user = $commercial->user;
        $token = Str::random(64);

        DB::transaction(function () use ($user, $token, $status): void {
            $this->expire($user);
            PasswordReset::query()->create([
                'authenticatable_type' => $user->getMorphClass(),
                'authenticatable_id' => $user->getKey(),
                'token_hash' => AuthSessions::hash($token),
                'expires_at' => now()->addHours(self::VALID_HOURS),
            ]);

            if ($status === 'actif') {
                $this->sessions->revokeAll($user, 'access_reset_by_admin');
            }
        });

        $this->deliver($user, $token, $status === 'actif');
        AdminAudit::record($admin, 'commercials', 'commercial.access_link_sent', $request, $commercial, null, ['status' => $status, 'email' => $user->email !== null]);
    }

    public function revoke(CommercialProfile $commercial, Admin $admin, Request $request): void
    {
        if (! $this->pending($commercial->user)) {
            throw ValidationException::withMessages(['commercial' => 'Aucun lien en cours.']);
        }

        $this->expire($commercial->user);
        AdminAudit::record($admin, 'commercials', 'commercial.access_link_revoked', $request, $commercial);
    }

    /**
     * Le lien ne fonctionne plus (compte suspendu ou terminé, nouveau lien).
     */
    public function expire(User $user): void
    {
        $this->links($user)->whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
    }

    /**
     * État du dernier lien.
     *
     * @return array{state: array{value: string, label: string}, sent_at: string|null, expires_at: string|null, used_at: string|null}
     */
    public function state(User $user): array
    {
        $link = $this->links($user)->latest()->latest('id')->first();
        [$value, $label] = match (true) {
            ! $link => ['aucun', 'Aucun lien envoyé'],
            $link->used_at !== null => ['utilise', 'Utilisé'],
            $link->expires_at->isFuture() => ['en_cours', 'En cours de validité'],
            default => ['expire', 'Expiré ou révoqué'],
        };

        return [
            'state' => ['value' => $value, 'label' => $label],
            'sent_at' => $link?->created_at?->toIso8601String(),
            'expires_at' => $link?->expires_at?->toIso8601String(),
            'used_at' => $link?->used_at?->toIso8601String(),
        ];
    }

    /**
     * Page d’activation : à qui appartient le lien.
     *
     * @return array{first_name: string, phone: string|null, expires_at: string}
     */
    public function describe(string $token): array
    {
        [$link, $user] = $this->resolve($token);

        return ['first_name' => (string) $user->first_name, 'phone' => PhoneNumber::of($user)?->masked(), 'expires_at' => $link->expires_at->toIso8601String()];
    }

    /**
     * Enregistre le mot de passe choisi et ferme les sessions ouvertes.
     */
    public function complete(string $token, string $password): void
    {
        [$link, $user] = $this->resolve($token);

        DB::transaction(function () use ($link, $user, $password): void {
            $link->update(['used_at' => now()]);
            $user->forceFill(['password_hash' => $password, 'must_change_password' => false, 'failed_login_count' => 0, 'locked_until' => null])->save();
            $this->sessions->revokeAll($user, 'password_reset');
        });
    }

    private function pending(User $user): bool
    {
        return $this->links($user)->whereNull('used_at')->where('expires_at', '>', now())->exists();
    }

    /**
     * @return array{0: PasswordReset, 1: User}
     */
    private function resolve(string $token): array
    {
        $link = PasswordReset::query()->where('authenticatable_type', (new User)->getMorphClass())->where('token_hash', AuthSessions::hash($token))->whereNull('used_at')->where('expires_at', '>', now())->first();
        $user = $link ? User::query()->with('commercialProfile')->find($link->authenticatable_id) : null;

        if (! $user?->commercialProfile || $user->signInRefusal()) {
            throw ValidationException::withMessages(['token' => 'Ce lien a expiré ou n’est plus valide. Demandez-en un nouveau à votre responsable O’CHANTIER.']);
        }

        return [$link, $user];
    }

    /**
     * @return Builder<PasswordReset>
     */
    private function links(User $user)
    {
        return PasswordReset::query()->where('authenticatable_type', $user->getMorphClass())->where('authenticatable_id', $user->getKey());
    }

    private function deliver(User $user, string $token, bool $reset): void
    {
        $link = config('auth.commercial.activation_url').'?'.http_build_query(['token' => $token]);
        $action = $reset ? 'choisir un nouveau mot de passe' : 'activer votre compte commercial';
        $hours = self::VALID_HOURS;

        if ($phone = PhoneNumber::of($user)) {
            $this->sms->send($phone->e164(), "O’CHANTIER : pour {$action}, ouvrez ce lien (valable {$hours} h) : {$link}");
        }

        if ($user->email) {
            Mail::raw(
                "Bonjour {$user->first_name},\n\nPour {$action} O’CHANTIER, ouvrez ce lien (valable {$hours} heures) :\n{$link}\n\nVous vous connecterez ensuite à l’application O’CHANTIER Commercial avec votre numéro de téléphone. Ne transférez pas ce message.",
                fn ($mail) => $mail->to($user->email)->subject($reset ? 'Nouveau mot de passe O’CHANTIER Commercial' : 'Activez votre compte O’CHANTIER Commercial'),
            );
        }
    }
}
