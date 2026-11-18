<?php

namespace App\Support\Contractor;

use App\Enums\ContractorMemberRole;
use App\Enums\ContractorMemberStatus;
use App\Models\Contractor;
use App\Models\ContractorMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Accès au compte d’un entrepreneur : le titulaire (propriétaire du dossier) ou un collaborateur actif, avec les
 * droits de son rôle.
 *
 * Chaque route de l’API entrepreneur relève d’un droit (ROUTES, d’après le nom de la route) ; le titulaire a tous
 * les droits. Une route absente de la table est réservée au titulaire.
 */
class ContractorAccess
{
    public const OWNER = 'titulaire';

    /**
     * Droit => rôles qui l’ont (en plus du titulaire).
     *
     * @var array<string, list<string>>
     */
    public const ABILITIES = [
        'view' => ['chef_chantier', 'technicien', 'comptable', 'observateur'],
        'finance.view' => ['chef_chantier', 'comptable'],
        'billing.view' => ['comptable'],
        'team.manage' => ['chef_chantier'],
        'attendance.record' => ['chef_chantier', 'technicien'],
        'attendance.validate' => ['chef_chantier'],
    ];

    /**
     * Nom de route (sans « v1.contractor. ») => droit. Les motifs se terminent par « * ».
     *
     * @var array<string, string>
     */
    public const ROUTES = [
        'profile.show' => 'view',
        'notifications.*' => 'view',
        'notification-preferences.*' => 'view',
        'support.*' => 'view',
        'opportunities.index' => 'view',
        'opportunities.quotes.show' => 'view',
        'opportunities.offers.show' => 'view',
        'my-offers.index' => 'view',
        'offer-responses.show' => 'view',
        'quote-proposals.index' => 'view',
        'quote-proposals.show' => 'view',
        'workers.*' => 'view',
        'sites.index' => 'view',
        'sites.show' => 'view',
        'sites.documents' => 'view',
        'sites.timeline' => 'view',
        'sites.team.index' => 'view',
        'sites.assignments.show' => 'view',
        'sites.attendance.index' => 'view',
        'sites.attendance.history' => 'view',
        'sites.attendance.days.show' => 'view',
        'sites.staffing.index' => 'view',
        'sites.staffing.show' => 'view',
        'sites.ratings.index' => 'view',
        'sites.payments' => 'finance.view',
        'sites.report' => 'finance.view',
        'sites.report.pdf' => 'finance.view',
        'payments.installments' => 'finance.view',
        'subscription.show' => 'billing.view',
        'subscription.invoices.pdf' => 'billing.view',
        'sites.steps.*' => 'team.manage',
        'sites.assignments.store' => 'team.manage',
        'sites.assignments.replace' => 'team.manage',
        'sites.assignments.cancel' => 'team.manage',
        'sites.staffing.store' => 'team.manage',
        'sites.staffing.refuse' => 'team.manage',
        'sites.staffing.cancel' => 'team.manage',
        'sites.ratings.store' => 'team.manage',
        'ratings.update' => 'team.manage',
        'sites.attendance.events.store' => 'attendance.record',
        'sites.attendance.absences.store' => 'attendance.record',
        'sites.attendance.validate' => 'attendance.validate',
        'sites.attendance.days.correct' => 'attendance.validate',
    ];

    /**
     * @var array<string, ContractorMember|null>
     */
    private array $memberships = [];

    /**
     * Compte de l’entrepreneur dont l’utilisateur est titulaire ou collaborateur actif.
     */
    public function contractor(User $user): ?Contractor
    {
        return Contractor::query()->where('user_id', $user->getKey())->first() ?? $this->membership($user)?->contractor;
    }

    /**
     * Rôle de l’utilisateur dans le compte (titulaire ou rôle du collaborateur).
     */
    public function role(User $user): ?string
    {
        if (Contractor::query()->where('user_id', $user->getKey())->exists()) {
            return self::OWNER;
        }

        return $this->membership($user)?->role->value;
    }

    public function isOwner(User $user): bool
    {
        return $this->role($user) === self::OWNER;
    }

    /**
     * Droits de l’utilisateur : tous pour le titulaire, ceux du rôle pour un collaborateur.
     *
     * @return list<string>
     */
    public function abilities(User $user): array
    {
        $role = $this->role($user);

        return match (true) {
            $role === self::OWNER => [...array_keys(self::ABILITIES), 'account.manage'],
            $role === null => [],
            default => array_keys(array_filter(self::ABILITIES, fn (array $roles) => in_array($role, $roles, true))),
        };
    }

    /**
     * L’utilisateur peut-il appeler la route ? Sans compte (profil à choisir), seul le titulaire potentiel passe.
     */
    public function allows(User $user, ?string $routeName): bool
    {
        $role = $this->role($user);

        if ($role === null || $role === self::OWNER) {
            return true;
        }

        $name = Str::after((string) $routeName, 'v1.contractor.');
        $ability = collect(self::ROUTES)->first(fn (string $ability, string $pattern) => Str::is($pattern, $name));

        return $ability !== null && in_array($ability, $this->abilities($user), true);
    }

    public function forget(User $user): void
    {
        unset($this->memberships[$user->getKey()]);
    }

    private function membership(User $user): ?ContractorMember
    {
        if (! array_key_exists($user->getKey(), $this->memberships)) {
            $this->memberships[$user->getKey()] = ContractorMember::query()->with('contractor')
                ->where('user_id', $user->getKey())
                ->where('status', ContractorMemberStatus::Actif->value)
                ->first();
        }

        return $this->memberships[$user->getKey()];
    }

    /**
     * Libellé du rôle.
     */
    public static function roleLabel(?string $role): ?string
    {
        return $role === self::OWNER ? 'Titulaire' : ContractorMemberRole::tryFrom((string) $role)?->label();
    }
}
