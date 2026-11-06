<?php

namespace Database\Seeders;

use App\Enums\AdminStatus;
use App\Enums\PermissionAction;
use App\Models\Admin;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Permissions du Back Office (module × action), rôles système et premier super administrateur.
 *
 * Le super administrateur est créé à partir de ADMIN_EMAIL et ADMIN_PASSWORD. Sans mot de passe, un mot de passe
 * provisoire est généré et affiché (refusé en production) ; il doit être changé à la première connexion.
 */
class AdminAccessSeeder extends Seeder
{
    /**
     * Module => [libellé, actions disponibles].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const MODULES = [
        'dashboard' => ['tableau de bord', ['read']],
        'promoters' => ['promoteurs', ['read', 'create', 'update', 'validate', 'export', 'delete']],
        'organizations' => ['organisations', ['read', 'update', 'validate', 'export']],
        'subscriptions' => ['abonnements', ['read', 'update', 'approve', 'export']],
        'commercials' => ['commerciaux', ['read', 'create', 'update', 'validate', 'export', 'delete']],
        'prospects' => ['prospects', ['read', 'update', 'export']],
        'workers' => ['ouvriers', ['read', 'create', 'update', 'validate', 'export', 'delete']],
        'documents' => ['documents', ['read', 'validate']],
        'sites' => ['chantiers', ['read', 'update', 'validate', 'export']],
        'offers' => ['offres et besoins', ['read', 'update', 'validate', 'export']],
        'matching' => ['matching', ['read', 'create', 'update', 'approve']],
        'missions' => ['missions et affectations', ['read', 'create', 'update', 'export']],
        'attendance' => ['pointages', ['read', 'update', 'validate', 'export']],
        'payments' => ['paiements', ['read', 'update', 'approve', 'export']],
        'payouts' => ['reversements', ['read', 'create', 'approve', 'export']],
        'commercial_earnings' => ['gains des commerciaux', ['read', 'update', 'approve', 'export']],
        'finance' => ['finance et rapprochements', ['read', 'update', 'approve', 'export']],
        'ratings' => ['avis', ['read', 'update', 'validate']],
        'disputes' => ['litiges et réclamations', ['read', 'update', 'validate', 'approve']],
        'tickets' => ['support', ['read', 'create', 'update']],
        'notifications' => ['notifications et campagnes', ['read', 'create', 'update', 'approve']],
        'content' => ['contenus (FAQ, textes légaux)', ['read', 'create', 'update', 'delete']],
        'reports' => ['rapports', ['read', 'create', 'export']],
        'settings' => ['paramètres', ['read', 'update', 'approve']],
        'integrations' => ['intégrations', ['read', 'update']],
        'admins' => ['administrateurs et rôles', ['read', 'create', 'update', 'delete']],
        'audit' => ['journal d’audit', ['read', 'export']],
    ];

    /**
     * Rôle => [libellé, description, droits par module]. « * » désigne tous les modules ou toutes les actions.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, list<string>>}>
     */
    private const ROLES = [
        'super_admin' => ['Super administrateur', 'Accès complet, y compris la gestion des administrateurs.', ['*' => ['*']]],
        'direction' => ['Direction', 'Pilotage : consultation et export de tous les modules, approbations financières et paramètres.', [
            '*' => ['read', 'export'],
            'payments' => ['approve'], 'payouts' => ['approve'], 'finance' => ['approve'], 'commercial_earnings' => ['approve'], 'settings' => ['approve'], 'matching' => ['approve'], 'disputes' => ['validate', 'approve'],
        ]],
        'operations' => ['Opérations', 'Contrôle des profils, chantiers, matching, affectations et pointages.', [
            'dashboard' => ['*'], 'promoters' => ['read', 'create', 'update', 'validate', 'export'], 'organizations' => ['read', 'update', 'validate'], 'commercials' => ['*'], 'prospects' => ['*'],
            'workers' => ['read', 'create', 'update', 'validate', 'export'], 'documents' => ['*'], 'sites' => ['*'], 'offers' => ['*'], 'matching' => ['read', 'create', 'update'],
            'missions' => ['*'], 'attendance' => ['*'], 'ratings' => ['*'], 'disputes' => ['read', 'update'], 'tickets' => ['read'], 'reports' => ['read', 'export'],
        ]],
        'finance' => ['Finance', 'Paiements, reversements, abonnements, gains des commerciaux et rapprochements.', [
            'dashboard' => ['*'], 'promoters' => ['read'], 'organizations' => ['read'], 'subscriptions' => ['*'], 'commercials' => ['read'], 'workers' => ['read'], 'sites' => ['read'],
            'missions' => ['read'], 'attendance' => ['read', 'export'], 'payments' => ['*'], 'payouts' => ['*'], 'commercial_earnings' => ['*'], 'finance' => ['*'],
            'disputes' => ['read'], 'reports' => ['*'], 'settings' => ['read'],
        ]],
        'support' => ['Support', 'Assistance aux utilisateurs, avis, litiges et contenus d’aide.', [
            'dashboard' => ['*'], 'promoters' => ['read'], 'organizations' => ['read'], 'subscriptions' => ['read'], 'commercials' => ['read'], 'workers' => ['read'],
            'sites' => ['read'], 'missions' => ['read'], 'ratings' => ['*'], 'disputes' => ['read', 'update'], 'tickets' => ['*'], 'notifications' => ['read', 'create', 'update'], 'content' => ['*'],
        ]],
        'lecture_seule' => ['Lecture seule', 'Consultation des modules métier, sans modification.', [
            '*' => ['read'], 'admins' => [], 'audit' => [], 'integrations' => [],
        ]],
    ];

    public function run(): void
    {
        $permissions = $this->seedPermissions();

        $roles = collect(self::ROLES)->map(function (array $role, string $code) use ($permissions): AdminRole {
            [$label, $description, $grants] = $role;

            $model = AdminRole::query()->updateOrCreate(['code' => $code], ['label' => $label, 'description' => $description, 'is_system' => true]);
            $model->permissions()->sync($this->grantedPermissionIds($grants, $permissions));

            return $model;
        });

        $this->seedSuperAdmin($roles['super_admin']);
    }

    /**
     * @return array<string, string> code de permission => identifiant
     */
    private function seedPermissions(): array
    {
        $ids = [];

        foreach (self::MODULES as $module => [$label, $actions]) {
            foreach ($actions as $action) {
                $permission = AdminPermission::query()->updateOrCreate(['code' => "{$module}.{$action}"], [
                    'module' => $module,
                    'action' => PermissionAction::from($action),
                    'label' => PermissionAction::from($action)->label().' · '.$label,
                ]);
                $ids[$permission->code] = $permission->getKey();
            }
        }

        return $ids;
    }

    /**
     * Les droits d’un module explicite s’ajoutent à ceux de « * » ; une liste vide retire le module.
     *
     * @param  array<string, list<string>>  $grants
     * @param  array<string, string>  $permissions
     * @return list<string>
     */
    private function grantedPermissionIds(array $grants, array $permissions): array
    {
        $ids = [];

        foreach (self::MODULES as $module => [, $actions]) {
            $granted = ($grants[$module] ?? null) === [] ? [] : [...($grants['*'] ?? []), ...($grants[$module] ?? [])];

            foreach ($actions as $action) {
                if (in_array('*', $granted, true) || in_array($action, $granted, true)) {
                    $ids[] = $permissions["{$module}.{$action}"];
                }
            }
        }

        return $ids;
    }

    private function seedSuperAdmin(AdminRole $role): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@ochantier.ci');

        if (Admin::query()->where('email', $email)->exists()) {
            return;
        }

        $password = (string) env('ADMIN_PASSWORD');

        if ($password === '') {
            if (app()->isProduction()) {
                throw new RuntimeException('Définissez ADMIN_PASSWORD pour créer le premier administrateur.');
            }

            $password = Str::password(16, symbols: false);
            $this->command?->warn("Super administrateur {$email} · mot de passe provisoire : {$password}");
        }

        $admin = Admin::query()->create([
            'first_name' => (string) env('ADMIN_FIRST_NAME', 'Administrateur'),
            'last_name' => (string) env('ADMIN_LAST_NAME', 'O’CHANTIER'),
            'email' => $email,
            'department' => 'direction',
            'password_hash' => $password,
            'status' => AdminStatus::Actif,
            'mfa_enabled' => true,
            'must_change_password' => true,
        ]);

        $admin->roles()->attach($role->getKey());
    }
}
