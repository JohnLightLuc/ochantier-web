<?php

namespace App\Http\Controllers;

use App\Models\SettingChange;
use App\Models\User;
use App\Support\AdminRoles;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Comptes administratifs : invitation, changement de rôle, suspension et réactivation. Un administrateur ne peut
 * ni suspendre son propre compte ni modifier son propre rôle, et le dernier Super administrateur actif est protégé.
 */
class AdminUserController extends Controller
{
    private const SECTION = 'Utilisateurs & rôles';

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(array_keys(AdminRoles::ROLES))],
            'modules' => ['nullable', 'string', 'max:160'],
        ], [
            'name.required' => 'Saisissez le nom complet.',
            'email.required' => 'Saisissez l’adresse e-mail.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
        ]);
        $this->guardAdminLimit($data['role'], 'role');
        $hours = (int) Settings::value(['code' => 'usr.invite', 'default' => 72]);
        $user = User::create([...$data, 'password' => Str::random(40), 'status' => 'Invitation envoyée', 'two_factor' => false, 'invitation_expires_on' => now()->addHours($hours)]);
        SettingChange::record(self::SECTION, "Invitation envoyée à {$user->name} ({$user->role})", null, $user->email);
        Inertia::flash('toast', "Invitation envoyée à {$user->email}.");

        return back();
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(AdminRoles::ROLES))]]);
        $this->guardSelf($request, $user, 'role', 'Vous ne pouvez pas modifier votre propre rôle.');
        if ($data['role'] === $user->role) {
            Inertia::flash('toast', 'Aucune modification à enregistrer.');

            return back();
        }
        $this->guardLastSuperAdmin($user, 'role');
        $this->guardAdminLimit($data['role'], 'role', $user);
        $old = $user->role;
        $user->update(['role' => $data['role']]);
        SettingChange::record(self::SECTION, "Rôle de {$user->name} modifié de {$old} à {$user->role}", $old, $user->role);
        Inertia::flash('toast', "Rôle de {$user->name} mis à jour.");

        return back();
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        if ($user->status === 'Suspendu') {
            $user->update(['status' => $user->last_login_at ? 'Actif' : 'Invitation envoyée']);
            SettingChange::record(self::SECTION, "Compte de {$user->name} réactivé", 'Suspendu', $user->status);
            Inertia::flash('toast', "Compte de {$user->name} réactivé.");

            return back();
        }
        $this->guardSelf($request, $user, 'status', 'Vous ne pouvez pas suspendre votre propre compte.');
        $this->guardLastSuperAdmin($user, 'status');
        $old = $user->status;
        $user->update(['status' => 'Suspendu']);
        SettingChange::record(self::SECTION, "Compte de {$user->name} suspendu", $old, 'Suspendu');
        Inertia::flash('toast', "Compte de {$user->name} suspendu.");

        return back();
    }

    public function resend(User $user): RedirectResponse
    {
        if ($user->status !== 'Invitation envoyée') {
            throw ValidationException::withMessages(['user' => 'Ce compte n’a pas d’invitation en attente.']);
        }
        $user->update(['invitation_expires_on' => now()->addHours((int) Settings::value(['code' => 'usr.invite', 'default' => 72]))]);
        SettingChange::record(self::SECTION, "Invitation renvoyée à {$user->name}", null, $user->email);
        Inertia::flash('toast', "Invitation renvoyée à {$user->email}.");

        return back();
    }

    private function guardSelf(Request $request, User $user, string $key, string $message): void
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    private function guardLastSuperAdmin(User $user, string $key): void
    {
        if ($user->role === 'Super administrateur' && $user->status === 'Actif' && User::query()->where('role', 'Super administrateur')->where('status', 'Actif')->count() <= 1) {
            throw ValidationException::withMessages([$key => 'Le dernier Super administrateur actif ne peut pas être modifié ni suspendu.']);
        }
    }

    private function guardAdminLimit(string $role, string $key, ?User $except = null): void
    {
        $max = (int) Settings::value(['code' => 'usr.maxAdmins', 'default' => 5]);
        if (in_array($role, AdminRoles::ADMIN_ROLES, true) && User::query()->whereIn('role', AdminRoles::ADMIN_ROLES)->where('status', '!=', 'Suspendu')->when($except, fn ($q) => $q->whereKeyNot($except->id))->count() >= $max) {
            throw ValidationException::withMessages([$key => "Limite atteinte : {$max} comptes administrateurs au maximum (paramètre « Administrateurs maximum »)."]);
        }
    }
}
