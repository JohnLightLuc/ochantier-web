<?php

namespace Database\Seeders;

use App\Enums\AdminStatus;
use App\Enums\CommercialProfileStatus;
use App\Enums\CommercialProfileVerificationStatus;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\CommercialProfile;
use App\Models\Commune;
use App\Models\PromoterProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comptes de démonstration pour tester les applications (jamais en production).
 *
 * Mot de passe des administrateurs et des commerciaux : « motdepasse1 » (« provisoire1 » pour le compte à activer).
 * Hors production, le code OTP 123456 (OTP_TEST_CODE) est accepté.
 */
class DemoAccountsSeeder extends Seeder
{
    public const PASSWORD = 'motdepasse1';

    /**
     * E-mail => [prénom, nom, service, rôle, statut].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: AdminStatus}>
     */
    private const ADMINS = [
        'a.kone@ochantier.ci' => ['Abdoulaye', 'Koné', 'operations', 'operations', AdminStatus::Actif],
        'm.diabate@ochantier.ci' => ['Moussa', 'Diabaté', 'finance', 'finance', AdminStatus::Actif],
        's.tanoh@ochantier.ci' => ['Serge', 'Tanoh', 'support', 'support', AdminStatus::Actif],
        'n.yao@ochantier.ci' => ['Nathalie', 'Yao', 'direction', 'direction', AdminStatus::Actif],
        'y.gbagbo@ochantier.ci' => ['Yannick', 'Gbagbo', 'operations', 'lecture_seule', AdminStatus::Suspendu],
    ];

    public function run(): void
    {
        $this->seedAdmins();
        $this->seedPromoters();
        $this->seedCommercials();
    }

    private function seedAdmins(): void
    {
        $roles = AdminRole::query()->pluck('id', 'code');

        foreach (self::ADMINS as $email => [$firstName, $lastName, $department, $role, $status]) {
            if (Admin::query()->where('email', $email)->exists()) {
                continue;
            }

            $admin = Admin::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'department' => $department,
                'password_hash' => self::PASSWORD,
                'status' => $status,
                'must_change_password' => false,
                'suspended_at' => $status === AdminStatus::Suspendu ? now() : null,
                'suspended_reason' => $status === AdminStatus::Suspendu ? 'Compte de démonstration suspendu.' : null,
            ]);

            $admin->roles()->attach($roles[$role]);
        }
    }

    /**
     * Un particulier connecté par téléphone, une entreprise connectée par e-mail.
     */
    private function seedPromoters(): void
    {
        $cocody = Commune::query()->where('name', 'Cocody')->value('id');

        $individual = $this->user(UserType::Promoter, '+2250700000001', null, 'Koffi', 'Kouassi');
        PromoterProfile::query()->firstOrCreate(['user_id' => $individual->getKey()], [
            'profile_kind' => PromoterProfileKind::Particulier,
            'commune_id' => $cocody,
            'verification_status' => PromoterVerificationStatus::Valide,
            'validated_at' => now(),
        ]);

        $company = $this->user(UserType::Promoter, '+2250700000002', 'promoteur@demo.ochantier.ci', 'Mariam', 'Traoré');
        PromoterProfile::query()->firstOrCreate(['user_id' => $company->getKey()], [
            'profile_kind' => PromoterProfileKind::Entreprise,
            'commune_id' => $cocody,
            'verification_status' => PromoterVerificationStatus::Brouillon,
        ]);

        $organization = $company->ownedOrganizations()->firstOrCreate([], [
            'legal_name' => 'Bâtisseurs d’Angré SARL',
            'trade_name' => 'Bâtisseurs d’Angré',
            'legal_form' => 'SARL',
            'commune_id' => $cocody,
            'email' => 'promoteur@demo.ochantier.ci',
            'legal_representative' => 'Mariam Traoré',
        ]);
        $organization->members()->firstOrCreate(['user_id' => $company->getKey()], [
            'role' => OrganizationRole::Administrateur,
            'status' => OrganizationMemberStatus::Actif,
            'joined_at' => now(),
        ]);
    }

    /**
     * Un commercial actif rattaché à Cocody, un compte créé par le Back Office avec mot de passe provisoire.
     */
    private function seedCommercials(): void
    {
        $creator = Admin::query()->where('email', 'a.kone@ochantier.ci')->value('id');

        $active = $this->user(UserType::Commercial, '+2250500000001', 'commercial@demo.ochantier.ci', 'Adama', 'Coulibaly', self::PASSWORD);
        $profile = $this->commercialProfile($active, 'COM-00001', $creator);

        if ($profile->wasRecentlyCreated) {
            $profile->commercialZones()->create([
                'commune_id' => Commune::query()->where('name', 'Cocody')->value('id'),
                'is_primary' => true,
                'effective_from' => now()->toDateString(),
                'assigned_by_admin_id' => $creator,
            ]);
        }

        $provisional = $this->user(UserType::Commercial, '+2250500000002', null, 'Aïcha', 'Touré', 'provisoire1', provisional: true);
        $this->commercialProfile($provisional, 'COM-00002', $creator);
    }

    private function commercialProfile(User $user, string $employeeNumber, ?string $creator): CommercialProfile
    {
        return CommercialProfile::query()->firstOrCreate(['user_id' => $user->getKey()], [
            'employee_number' => $employeeNumber,
            'verification_status' => CommercialProfileVerificationStatus::Valide,
            'status' => CommercialProfileStatus::Actif,
            'started_at' => now(),
            'created_by_admin_id' => $creator,
        ]);
    }

    /**
     * Crée l’utilisateur s’il n’existe pas encore (type et numéro).
     */
    private function user(UserType $type, string $phone, ?string $email, string $firstName, string $lastName, ?string $password = null, bool $provisional = false): User
    {
        return User::query()->firstOrCreate(['user_type' => $type, 'phone' => $phone], [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password_hash' => $password,
            'status' => $provisional ? UserStatus::EnAttenteActivation : UserStatus::Actif,
            'must_change_password' => $provisional,
            'phone_verified_at' => $provisional ? null : now(),
            'email_verified_at' => $email && ! $provisional ? now() : null,
        ]);
    }
}
