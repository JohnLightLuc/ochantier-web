<?php

namespace Database\Seeders;

use App\Enums\AdminStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\CommercialProfileStatus;
use App\Enums\CommercialProfileVerificationStatus;
use App\Enums\Gender;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Enums\SkillLevel;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\CommercialProfile;
use App\Models\Commune;
use App\Models\PromoterProfile;
use App\Models\Trade;
use App\Models\User;
use App\Models\Worker;
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

    /**
     * Ouvriers validés recrutés par le commercial de démonstration : nom => [métier, tarif net, années, note,
     * commune, disponible].
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: float, 4: string, 5: bool}>
     */
    private const WORKERS = [
        'Kouamé Koffi' => ['macon', 22700, 5, 4.7, 'Cocody', true],
        'Bamba Seydou' => ['macon', 22000, 6, 4.6, 'Yopougon', true],
        'Touré Ali' => ['macon', 23600, 9, 4.9, 'Abobo', true],
        'Yao Koffi' => ['macon', 20000, 6, 4.5, 'Marcory', false],
        'Awa Traoré' => ['electricien', 27300, 7, 4.8, 'Cocody', true],
        'Ibrahim Diallo' => ['electricien', 25500, 3, 4.6, 'Plateau', true],
        'Yao Konan' => ['plombier', 18200, 4, 4.6, 'Cocody', true],
        'Koné Moussa' => ['plombier', 18200, 5, 4.5, 'Koumassi', true],
        'Serge Aka' => ['peintre', 16400, 6, 4.7, 'Yopougon', true],
        'N’Guessan Paul' => ['carreleur', 20000, 6, 4.8, 'Treichville', true],
        'Kablan Éric' => ['ferrailleur', 18200, 5, 4.6, 'Adjamé', true],
        'Ouattara Lamine' => ['ferrailleur', 18200, 6, 4.7, 'Port-Bouët', true],
    ];

    public function run(): void
    {
        $this->seedAdmins();
        $this->seedPromoters();
        $this->seedCommercials();
        $this->seedWorkers();
    }

    /**
     * Ouvriers validés et disponibles, pour le catalogue et la simulation du matching (ochantier:simulate-matching).
     */
    private function seedWorkers(): void
    {
        $commercial = CommercialProfile::query()->where('employee_number', 'COM-00001')->firstOrFail();
        $trades = Trade::query()->pluck('id', 'code');
        $communes = Commune::query()->pluck('id', 'name');
        $index = 0;

        foreach (self::WORKERS as $name => [$trade, $rate, $years, $rating, $commune, $available]) {
            $index++;
            [$firstName, $lastName] = explode(' ', $name, 2);

            $worker = Worker::query()->firstOrCreate(['dial_code' => '225', 'phone_number' => sprintf('01000000%02d', $index)], [
                'reference' => sprintf('OUV-D%05d', $index),
                'origin_commercial_id' => $commercial->getKey(),
                'current_commercial_id' => $commercial->getKey(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $name === 'Awa Traoré' ? Gender::F : Gender::M,
                'residence_commune_id' => $communes[$commune] ?? null,
                'acquisition_channel' => 'terrain',
                'estimated_daily_rate' => $rate,
                'estimated_daily_rate_at' => now()->toDateString(),
                'verification_status' => WorkerVerificationStatus::Valide,
                'validated_at' => now(),
                'availability_status' => $available ? AvailabilityStatus::Disponible : AvailabilityStatus::Occupe,
                'rating_avg' => $rating,
                'rating_count' => 10 + $index,
                'missions_completed' => 3 + $index,
            ]);

            if ($worker->wasRecentlyCreated) {
                $worker->workerTrades()->create(['trade_id' => $trades[$trade], 'is_primary' => true, 'years_experience' => $years, 'level' => $years >= 5 ? SkillLevel::Confirme : SkillLevel::Intermediaire]);
            }
        }
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

        $individual = $this->user(UserType::Promoter, '0700000001', null, 'Koffi', 'Kouassi');
        PromoterProfile::query()->firstOrCreate(['user_id' => $individual->getKey()], [
            'profile_kind' => PromoterProfileKind::Particulier,
            'commune_id' => $cocody,
            'verification_status' => PromoterVerificationStatus::Valide,
            'validated_at' => now(),
        ]);

        $company = $this->user(UserType::Promoter, '0700000002', 'promoteur@demo.ochantier.ci', 'Mariam', 'Traoré');
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

        $active = $this->user(UserType::Commercial, '0500000001', 'commercial@demo.ochantier.ci', 'Adama', 'Coulibaly', self::PASSWORD);
        $profile = $this->commercialProfile($active, 'COM-00001', $creator);

        if ($profile->wasRecentlyCreated) {
            $profile->commercialZones()->create([
                'commune_id' => Commune::query()->where('name', 'Cocody')->value('id'),
                'is_primary' => true,
                'effective_from' => now()->toDateString(),
                'assigned_by_admin_id' => $creator,
            ]);
        }

        $provisional = $this->user(UserType::Commercial, '0500000002', null, 'Aïcha', 'Touré', 'provisoire1', provisional: true);
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
     * Crée l’utilisateur s’il n’existe pas encore (type et numéro ivoirien, indicatif 225).
     */
    private function user(UserType $type, string $phoneNumber, ?string $email, string $firstName, string $lastName, ?string $password = null, bool $provisional = false): User
    {
        return User::query()->firstOrCreate(['user_type' => $type, 'dial_code' => '225', 'phone_number' => $phoneNumber], [
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
