<?php

namespace App\Support\Admin;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PasswordReset;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\AuthSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comptes du Back Office : invitation par lien, rôles, suspension et désactivation.
 *
 * L’invité choisit son mot de passe depuis un lien de 72 h, sur la page de réinitialisation ; le compte devient actif à
 * ce moment-là. Personne ne modifie son propre accès, et un compte ne se donne ni ne retire le rôle de super
 * administrateur sans l’avoir ; il reste toujours au moins un super administrateur actif.
 */
class AdminAccounts
{
    public const LINK_HOURS = 72;

    private const SUPER = 'super_admin';

    public function __construct(private AdminSessions $sessions) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone?: string|null, department?: string|null, roles: list<string>}  $data
     */
    public function invite(array $data, Admin $admin, Request $request): Admin
    {
        $roles = $this->roles($data['roles'], $admin);

        $invited = DB::transaction(function () use ($data, $roles, $admin, $request): Admin {
            $invited = Admin::query()->create([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'department' => $data['department'] ?? null,
                'status' => AdminStatus::Invite,
                'must_change_password' => false,
                'invited_by_admin_id' => $admin->getKey(),
            ]);
            $this->assign($invited, $roles, $admin);
            AdminAudit::record($admin, 'admins', 'admins.invited', $request, $invited, null, ['email' => $invited->email, 'roles' => $data['roles']]);

            return $invited;
        });

        $this->sendLink($invited);

        return $invited;
    }

    public function resend(Admin $target, Admin $admin, Request $request): void
    {
        $this->ensure($target->status === AdminStatus::Invite, 'Ce compte est déjà activé.');
        $this->sendLink($target);
        AdminAudit::record($admin, 'admins', 'admins.invitation_resent', $request, $target);
    }

    public function revokeLink(Admin $target, Admin $admin, Request $request): void
    {
        $this->ensure($this->pendingLink($target), 'Aucune invitation en cours.');
        $this->expireLinks($target);
        AdminAudit::record($admin, 'admins', 'admins.invitation_revoked', $request, $target);
    }

    /**
     * @param  list<string>  $codes
     */
    public function updateRoles(Admin $target, array $codes, Admin $admin, Request $request): void
    {
        $this->ensureOther($target, $admin);
        $roles = $this->roles($codes, $admin);
        $before = $target->roles->pluck('code')->all();

        $this->ensure(! in_array(self::SUPER, $before, true) || in_array(self::SUPER, $codes, true) || $this->otherSuperAdmins($target), 'Il doit rester au moins un super administrateur actif.');
        $this->ensure(! in_array(self::SUPER, $before, true) || $this->isSuper($admin), 'Seul un super administrateur peut modifier ce compte.');

        DB::transaction(function () use ($target, $roles, $admin, $request, $before, $codes): void {
            $target->roles()->detach();
            $this->assign($target, $roles, $admin);
            $this->sessions->revokeAll($target, 'roles_changed');
            AdminAudit::record($admin, 'admins', 'admins.roles_updated', $request, $target, ['roles' => $before], ['roles' => $codes]);
        });
    }

    public function suspend(Admin $target, string $reason, Admin $admin, Request $request): void
    {
        $this->ensureOther($target, $admin);
        $this->ensure($target->status === AdminStatus::Actif, 'Ce compte n’est pas actif.');
        $this->ensureNotLastSuper($target);

        DB::transaction(function () use ($target, $reason, $admin, $request): void {
            $target->forceFill(['status' => AdminStatus::Suspendu, 'suspended_at' => now(), 'suspended_reason' => $reason])->save();
            $this->sessions->revokeAll($target, 'suspended');
            AdminAudit::record($admin, 'admins', 'admins.suspended', $request, $target, null, ['reason' => $reason]);
        });
    }

    public function reactivate(Admin $target, Admin $admin, Request $request): void
    {
        $this->ensure($target->status === AdminStatus::Suspendu, 'Ce compte n’est pas suspendu.');

        $target->forceFill(['status' => AdminStatus::Actif, 'suspended_at' => null, 'suspended_reason' => null])->save();
        AdminAudit::record($admin, 'admins', 'admins.reactivated', $request, $target);
    }

    /**
     * Définitif : le compte garde sa trace dans le journal, mais ne se connecte plus.
     */
    public function deactivate(Admin $target, string $reason, Admin $admin, Request $request): void
    {
        $this->ensureOther($target, $admin);
        $this->ensure($target->status !== AdminStatus::Desactive, 'Ce compte est déjà désactivé.');
        $this->ensureNotLastSuper($target);

        DB::transaction(function () use ($target, $reason, $admin, $request): void {
            $target->forceFill(['status' => AdminStatus::Desactive])->save();
            $this->expireLinks($target);
            $this->sessions->revokeAll($target, 'deactivated');
            AdminAudit::record($admin, 'admins', 'admins.deactivated', $request, $target, null, ['reason' => $reason]);
        });
    }

    /**
     * @return array{invite: array{pending: bool, expires_at: string|null}, can: array<string, bool>}
     */
    public function state(Admin $target, Admin $admin): array
    {
        $link = PasswordReset::query()->where('authenticatable_type', $target->getMorphClass())->where('authenticatable_id', $target->getKey())->whereNull('used_at')->where('expires_at', '>', now())->latest()->first();
        $other = ! $target->is($admin);
        $invited = $target->status === AdminStatus::Invite;
        $protected = in_array(self::SUPER, $target->roles->pluck('code')->all(), true) && ! $this->isSuper($admin);

        return [
            'invite' => ['pending' => $invited && $link !== null, 'expires_at' => $invited ? $link?->expires_at?->toIso8601String() : null],
            'can' => [
                'resend' => $invited,
                'revoke' => $invited && $link !== null,
                'roles' => $other && ! $protected && $target->status !== AdminStatus::Desactive,
                'suspend' => $other && ! $protected && $target->status === AdminStatus::Actif,
                'reactivate' => ! $protected && $target->status === AdminStatus::Suspendu,
                'deactivate' => $other && ! $protected && $target->status !== AdminStatus::Desactive,
            ],
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<int, AdminRole>
     */
    private function roles(array $codes, Admin $admin)
    {
        $this->ensure(! in_array(self::SUPER, $codes, true) || $this->isSuper($admin), 'Seul un super administrateur peut attribuer ce rôle.');

        return AdminRole::query()->whereIn('code', $codes)->get();
    }

    /**
     * @param  Collection<int, AdminRole>  $roles
     */
    private function assign(Admin $target, $roles, Admin $admin): void
    {
        $target->roles()->attach($roles->modelKeys(), ['granted_by_admin_id' => $admin->getKey(), 'granted_at' => now()]);
    }

    private function sendLink(Admin $target): void
    {
        $token = Str::random(64);

        DB::transaction(function () use ($target, $token): void {
            $this->expireLinks($target);
            PasswordReset::query()->create([
                'authenticatable_type' => $target->getMorphClass(),
                'authenticatable_id' => $target->getKey(),
                'token_hash' => AuthSessions::hash($token),
                'expires_at' => now()->addHours(self::LINK_HOURS),
            ]);
        });

        $link = config('auth.admin.reset_url').'?'.http_build_query(['token' => $token, 'email' => $target->email]);
        $hours = self::LINK_HOURS;

        Mail::raw(
            "Bonjour {$target->first_name},\n\nUn accès au Back Office O’CHANTIER a été créé pour vous. Pour choisir votre mot de passe, ouvrez ce lien (valable {$hours} heures) :\n{$link}\n\nSi vous n’attendiez pas cette invitation, ignorez ce message.",
            fn ($mail) => $mail->to($target->email)->subject('Votre accès au Back Office O’CHANTIER'),
        );
    }

    private function expireLinks(Admin $target): void
    {
        PasswordReset::query()->where('authenticatable_type', $target->getMorphClass())->where('authenticatable_id', $target->getKey())->whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
    }

    private function pendingLink(Admin $target): bool
    {
        return PasswordReset::query()->where('authenticatable_type', $target->getMorphClass())->where('authenticatable_id', $target->getKey())->whereNull('used_at')->where('expires_at', '>', now())->exists();
    }

    private function isSuper(Admin $admin): bool
    {
        return $admin->roles()->where('code', self::SUPER)->exists();
    }

    private function otherSuperAdmins(Admin $target): bool
    {
        return Admin::query()->whereKeyNot($target->getKey())->where('status', AdminStatus::Actif)->whereHas('roles', fn ($query) => $query->where('code', self::SUPER))->exists();
    }

    private function ensureNotLastSuper(Admin $target): void
    {
        $this->ensure(! $this->isSuper($target) || $this->otherSuperAdmins($target), 'Il doit rester au moins un super administrateur actif.');
    }

    private function ensureOther(Admin $target, Admin $admin): void
    {
        $this->ensure(! $target->is($admin), 'Vous ne pouvez pas modifier votre propre accès.');
        $this->ensure(! $this->isSuper($target) || $this->isSuper($admin), 'Seul un super administrateur peut modifier ce compte.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['admin' => $message]);
        }
    }
}
