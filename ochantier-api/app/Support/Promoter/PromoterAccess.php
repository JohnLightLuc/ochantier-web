<?php

namespace App\Support\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationMember;
use App\Models\PromoterProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Compte promoteur de l’utilisateur connecté et droits de son rôle. Le compte est le profil du propriétaire : celui
 * de l’utilisateur lui-même, ou celui du propriétaire de l’organisation dont il est collaborateur actif. Un
 * utilisateur appartient à un seul compte. Sans abonnement en vigueur, seul le propriétaire accède au compte.
 *
 * Résolu une fois par requête HTTP (cache rattaché à la requête en cours).
 */
class PromoterAccess
{
    public const OWNER = 'proprietaire';

    /**
     * Rôles affichés dans l’application Promoteur.
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::OWNER => 'Propriétaire',
        'administrateur' => 'Administrateur',
        'chef_projet' => 'Chef de chantier',
        'responsable_chantier' => 'Technicien',
        'responsable_financier' => 'Comptable',
        'lecteur' => 'Observateur',
    ];

    /**
     * Droit => rôles qui l’ont. Le technicien n’agit que sur les chantiers dont il est responsable.
     *
     * @var array<string, list<string>>
     */
    public const ABILITIES = [
        'sites.view' => [self::OWNER, 'administrateur', 'chef_projet', 'responsable_chantier', 'responsable_financier', 'lecteur'],
        'sites.manage' => [self::OWNER, 'administrateur', 'chef_projet'],
        'proposals.decide' => [self::OWNER, 'administrateur', 'chef_projet'],
        'attendance.manage' => [self::OWNER, 'administrateur', 'chef_projet', 'responsable_chantier'],
        'steps.manage' => [self::OWNER, 'administrateur', 'chef_projet', 'responsable_chantier'],
        'replacements.manage' => [self::OWNER, 'administrateur', 'chef_projet'],
        'ratings.manage' => [self::OWNER, 'administrateur', 'chef_projet'],
        'payments.manage' => [self::OWNER, 'administrateur', 'responsable_financier'],
        'finance.view' => [self::OWNER, 'administrateur', 'chef_projet', 'responsable_financier', 'lecteur'],
        'team.manage' => [self::OWNER, 'administrateur'],
        'activity.view' => [self::OWNER, 'administrateur'],
        'account.manage' => [self::OWNER],
    ];

    /**
     * @var array<string, array{profile: PromoterProfile|null, role: string|null, member: OrganizationMember|null}>
     */
    private array $resolved = [];

    /**
     * Profil du compte (celui du propriétaire), ou null sans compte (collaborateur retiré).
     */
    public function profile(User $user): ?PromoterProfile
    {
        return $this->resolve($user)['profile'];
    }

    /**
     * Rôle dans le compte : proprietaire, ou le rôle de collaborateur (OrganizationRole).
     */
    public function role(User $user): ?string
    {
        return $this->resolve($user)['role'];
    }

    public function membership(User $user): ?OrganizationMember
    {
        return $this->resolve($user)['member'];
    }

    public function isOwner(User $user): bool
    {
        return $this->role($user) === self::OWNER;
    }

    public function can(User $user, string $ability): bool
    {
        $role = $this->role($user);

        return $role !== null && in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    /**
     * Le technicien ne voit que les chantiers dont il est responsable.
     */
    public function limitedToOwnSites(User $user): bool
    {
        return $this->role($user) === OrganizationRole::ResponsableChantier->value;
    }

    /**
     * Restreint une requête de chantiers à ceux que l’utilisateur peut voir.
     *
     * @template TBuilder of Builder|Relation
     *
     * @param  TBuilder  $sites
     * @return TBuilder
     */
    public function scopeSites(Builder|Relation $sites, User $user): Builder|Relation
    {
        return $sites
            ->where($sites->qualifyColumn('promoter_profile_id'), $this->profile($user)?->getKey())
            ->when($this->limitedToOwnSites($user), fn ($query) => $query->where($sites->qualifyColumn('responsible_user_id'), $user->getKey()));
    }

    /**
     * Compte, rôle et droits pour l’application (GET /me).
     *
     * @return array<string, mixed>|null
     */
    public function summary(User $user): ?array
    {
        $profile = $this->profile($user);

        if (! $profile) {
            return null;
        }

        $owner = $profile->user;
        $role = (string) $this->role($user);

        return [
            'profile_id' => $profile->id,
            'owner' => ['id' => $owner->id, 'name' => trim($owner->first_name.' '.$owner->last_name)],
            'company' => $owner->ownedOrganizations()->value('legal_name'),
            'role' => ['value' => $role, 'label' => self::ROLE_LABELS[$role] ?? $role],
            'is_owner' => $role === self::OWNER,
            'only_own_sites' => $this->limitedToOwnSites($user),
            'permissions' => collect(self::ABILITIES)->filter(fn (array $roles) => in_array($role, $roles, true))->keys()->values()->all(),
        ];
    }

    /**
     * Oublie le compte résolu (après un changement de rôle ou d’appartenance dans la même requête).
     */
    public function forget(User $user): void
    {
        $this->resolved = array_filter($this->resolved, fn (string $key) => ! str_starts_with($key, $user->getKey().'|'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{profile: PromoterProfile|null, role: string|null, member: OrganizationMember|null}
     */
    private function resolve(User $user): array
    {
        return $this->resolved[$user->getKey().'|'.spl_object_id(request())] ??= (function () use ($user): array {
            if ($own = $user->promoterProfile()->first()) {
                return ['profile' => $own, 'role' => self::OWNER, 'member' => null];
            }

            $member = OrganizationMember::query()
                ->where('user_id', $user->getKey())
                ->where('status', OrganizationMemberStatus::Actif)
                ->whereHas('organization.ownerUser.promoterProfile.subscriptions', fn (Builder $query) => $query->whereIn('status', PromoterSubscriptions::CURRENT))
                ->with('organization.ownerUser.promoterProfile')
                ->latest('joined_at')
                ->first();

            return $member
                ? ['profile' => $member->organization->ownerUser->promoterProfile, 'role' => $member->role->value, 'member' => $member]
                : ['profile' => null, 'role' => null, 'member' => null];
        })();
    }
}
