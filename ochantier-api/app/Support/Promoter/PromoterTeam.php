<?php

namespace App\Support\Promoter;

use App\Enums\MessageChannel;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\PhoneNumber;
use App\Support\Sms\SmsGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * « Utilisateurs et rôles » : le propriétaire (ou un administrateur) invite ses collaborateurs par SMS ou e-mail et
 * leur attribue un rôle, dans la limite des places de la formule. L’invitation, valable INVITATION_DAYS jours, est
 * acceptée à la première connexion de l’invité avec ce numéro ou cet e-mail.
 *
 * Les collaborateurs sont membres de l’organisation du propriétaire, créée à la première invitation pour un
 * particulier. Un utilisateur appartient à un seul compte : une personne qui a déjà son propre compte promoteur ne
 * peut pas être invitée.
 */
class PromoterTeam
{
    public const INVITATION_DAYS = 7;

    /**
     * Membres comptés dans l’équipe.
     */
    private const IN_TEAM = [OrganizationMemberStatus::Invite, OrganizationMemberStatus::Actif];

    public function __construct(
        private PromoterSubscriptions $subscriptions,
        private PromoterAccess $access,
        private AuthSessions $sessions,
        private SmsGateway $sms,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(PromoterProfile $profile, User $viewer): array
    {
        $subscription = $this->subscriptions->current($profile);
        $max = (int) ($subscription?->plan?->max_users ?? 1);
        $members = $this->members($profile);
        $used = 1 + $members->count();
        $owner = $profile->user;

        return [
            'subscribed' => $subscription !== null,
            'plan' => $subscription?->plan?->name ?? 'Solo',
            'seats' => ['used' => $used, 'max' => $max, 'label' => $used.' / '.$max.' utilisateur'.($max > 1 ? 's' : ''), 'left' => max(0, $max - $used)],
            'can_invite' => $this->refusal($profile) === null,
            'invite_refusal' => $this->refusal($profile),
            'users' => [
                [
                    'member_id' => null,
                    'user_id' => $owner->id,
                    'name' => $this->name($owner).($owner->is($viewer) ? ' (vous)' : ''),
                    'initials' => $this->initials($owner),
                    'contact' => PhoneNumber::of($owner)?->formatted() ?? $owner->email,
                    'role' => ['value' => PromoterAccess::OWNER, 'label' => PromoterAccess::ROLE_LABELS[PromoterAccess::OWNER]],
                    'status' => ['value' => 'actif', 'label' => 'Actif'],
                    'pending_text' => null,
                    'is_me' => $owner->is($viewer),
                    'removable' => false,
                    'role_editable' => false,
                ],
                ...$members->map(fn (OrganizationMember $member) => $this->member($member, $viewer))->all(),
            ],
            'roles' => $this->roles(),
        ];
    }

    /**
     * Invite un collaborateur ; une invitation encore en attente pour la même personne est renvoyée.
     *
     * @param  array{first_name: string, last_name?: string|null, role: string, channel: string, phone?: PhoneNumber|null, email?: string|null}  $data
     */
    public function invite(PromoterProfile $profile, User $inviter, array $data): OrganizationMember
    {
        if ($refusal = $this->refusal($profile)) {
            throw ValidationException::withMessages(['role' => $refusal]);
        }

        $channel = MessageChannel::from($data['channel']);
        $phone = $channel === MessageChannel::Sms ? $data['phone'] : null;
        $email = $channel === MessageChannel::Email ? mb_strtolower(trim((string) $data['email'])) : null;
        $field = $phone ? 'phone_number' : 'email';
        $existing = User::findByContact(UserType::Promoter, $phone?->e164(), $email);

        if ($existing?->is($profile->user)) {
            throw ValidationException::withMessages([$field => 'Vous ne pouvez pas vous inviter vous-même.']);
        }

        if ($existing && ($existing->promoterProfile()->exists() || $this->belongsElsewhere($existing, $profile))) {
            throw ValidationException::withMessages([$field => $phone ? 'Ce numéro est déjà utilisé par un autre compte O’CHANTIER.' : 'Cette adresse e-mail est déjà utilisée par un autre compte O’CHANTIER.']);
        }

        return DB::transaction(function () use ($profile, $inviter, $data, $channel, $phone, $email, $existing, $field): OrganizationMember {
            $organization = $this->organization($profile);
            $user = $existing ?? User::query()->create([
                'user_type' => UserType::Promoter,
                'first_name' => trim($data['first_name']),
                'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
                ...PhoneNumber::attributes($phone),
                'email' => $email,
                'status' => UserStatus::Actif,
            ]);

            /** @var OrganizationMember|null $member */
            $member = $organization->members()->where('user_id', $user->getKey())->first();

            if ($member?->status === OrganizationMemberStatus::Actif) {
                throw ValidationException::withMessages([$field => $this->name($user).' fait déjà partie de votre équipe.']);
            }

            $member = $organization->members()->updateOrCreate(['user_id' => $user->getKey()], [
                'role' => OrganizationRole::from($data['role']),
                'status' => OrganizationMemberStatus::Invite,
                'invited_by_user_id' => $inviter->getKey(),
                'joined_at' => null,
                'revoked_at' => null,
            ]);

            $this->pendingInvitations($user)->update(['cancelled_at' => now()]);
            Invitation::query()->create([
                'authenticatable_type' => $user->getMorphClass(),
                'authenticatable_id' => $user->getKey(),
                'channel' => $channel,
                'token_hash' => hash('sha256', Str::random(40)),
                'invited_by_user_id' => $inviter->getKey(),
                'sent_at' => now(),
                'expires_at' => now()->addDays(self::INVITATION_DAYS),
            ]);

            $this->send($channel, $phone?->e164() ?? (string) $email, $profile, $inviter, $member->role);

            return $member->load('user');
        });
    }

    public function changeRole(OrganizationMember $member, string $role): OrganizationMember
    {
        $member->update(['role' => OrganizationRole::from($role)]);
        $this->access->forget($member->user);

        return $member->load('user');
    }

    /**
     * Retire un collaborateur : accès révoqué, invitation annulée et sessions fermées.
     */
    public function remove(OrganizationMember $member): void
    {
        DB::transaction(function () use ($member): void {
            $member->update(['status' => OrganizationMemberStatus::Revoque, 'revoked_at' => now()]);
            $this->pendingInvitations($member->user)->update(['cancelled_at' => now()]);
            $this->sessions->revokeAll($member->user, 'access_revoked');
        });

        $this->access->forget($member->user);
    }

    /**
     * À la connexion de l’invité : ses invitations valides deviennent un accès actif au compte.
     */
    public function acceptInvitations(User $user): int
    {
        $invitation = $this->pendingInvitations($user)->where('expires_at', '>', now())->latest('sent_at')->first();

        if (! $invitation) {
            return 0;
        }

        $accepted = DB::transaction(function () use ($user, $invitation): int {
            $invitation->update(['accepted_at' => now()]);

            return OrganizationMember::query()
                ->where('user_id', $user->getKey())
                ->where('status', OrganizationMemberStatus::Invite)
                ->update(['status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);
        });

        $this->access->forget($user);

        return $accepted;
    }

    /**
     * Collaborateur du compte (invité ou actif), pour les routes /promoter/team/members/{member}.
     */
    public function findMember(PromoterProfile $profile, string $id): OrganizationMember
    {
        return OrganizationMember::query()
            ->whereKey($id)
            ->whereIn('status', self::IN_TEAM)
            ->whereHas('organization', fn ($query) => $query->where('owner_user_id', $profile->user_id))
            ->with('user')
            ->firstOrFail();
    }

    /**
     * Rôles attribuables à un collaborateur.
     *
     * @return list<array{value: string, label: string}>
     */
    public function roles(): array
    {
        return collect(OrganizationRole::cases())->map(fn (OrganizationRole $role) => ['value' => $role->value, 'label' => PromoterAccess::ROLE_LABELS[$role->value]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function member(OrganizationMember $member, User $viewer): array
    {
        $user = $member->user;
        $invitation = $member->status === OrganizationMemberStatus::Invite ? Invitation::query()
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getKey())
            ->whereNull('cancelled_at')
            ->latest('sent_at')
            ->first() : null;
        $expired = $invitation?->expires_at?->isPast() ?? false;

        return [
            'member_id' => $member->id,
            'user_id' => $user->id,
            'name' => $this->name($user).($user->is($viewer) ? ' (vous)' : ''),
            'initials' => $this->initials($user),
            'contact' => PhoneNumber::of($user)?->formatted() ?? $user->email,
            'role' => ['value' => $member->role->value, 'label' => PromoterAccess::ROLE_LABELS[$member->role->value]],
            'status' => $member->status === OrganizationMemberStatus::Actif
                ? ['value' => 'actif', 'label' => 'Actif']
                : ($expired ? ['value' => 'invitation_expiree', 'label' => 'Invitation expirée'] : ['value' => 'invite', 'label' => 'Invitation envoyée']),
            'pending_text' => $invitation ? ($expired ? 'Invitation expirée · renvoyez-la' : 'Invitation envoyée par '.($invitation->channel === MessageChannel::Email ? 'e-mail' : 'SMS')) : null,
            'is_me' => $user->is($viewer),
            'removable' => ! $user->is($viewer),
            'role_editable' => ! $user->is($viewer),
        ];
    }

    /**
     * Motif du refus d’une nouvelle invitation : pas d’abonnement ou plus de place.
     */
    private function refusal(PromoterProfile $profile): ?string
    {
        $subscription = $this->subscriptions->current($profile);

        if (! $subscription) {
            return 'Un abonnement est nécessaire pour inviter des collaborateurs.';
        }

        $max = (int) $subscription->plan->max_users;

        return $this->subscriptions->seatsUsed($profile) >= $max
            ? 'Limite de '.$max.' utilisateurs atteinte · passez à la formule supérieure.'
            : null;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    private function members(PromoterProfile $profile): Collection
    {
        $organization = $profile->user->ownedOrganizations()->first();

        return $organization
            ? $organization->members()->whereIn('status', self::IN_TEAM)->where('user_id', '!=', $profile->user_id)->with('user')->oldest()->get()
            : collect();
    }

    private function organization(PromoterProfile $profile): Organization
    {
        $owner = $profile->user;

        return $owner->ownedOrganizations()->first() ?? $owner->ownedOrganizations()->create([
            'legal_name' => $this->name($owner) ?: 'Compte équipe',
            'phone' => PhoneNumber::of($owner)?->e164(),
            'email' => $owner->email,
        ]);
    }

    /**
     * Collaborateur (invité ou actif) d’un autre compte.
     */
    private function belongsElsewhere(User $user, PromoterProfile $profile): bool
    {
        return OrganizationMember::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', self::IN_TEAM)
            ->whereHas('organization', fn ($query) => $query->where('owner_user_id', '!=', $profile->user_id))
            ->exists();
    }

    /**
     * @return Builder<Invitation>
     */
    private function pendingInvitations(User $user): Builder
    {
        return Invitation::query()
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getKey())
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at');
    }

    private function send(MessageChannel $channel, string $destination, PromoterProfile $profile, User $inviter, OrganizationRole $role): void
    {
        $company = $profile->user->ownedOrganizations()->value('legal_name') ?? $this->name($profile->user);
        $message = 'O’CHANTIER : '.$this->name($inviter).' vous invite à rejoindre « '.$company.' » en tant que '
            .PromoterAccess::ROLE_LABELS[$role->value].'. Installez l’application O’CHANTIER Promoteur et connectez-vous avec ce '
            .($channel === MessageChannel::Email ? 'e-mail' : 'numéro').' (invitation valable '.self::INVITATION_DAYS.' jours).';

        if ($channel === MessageChannel::Email) {
            Mail::raw($message, fn ($mail) => $mail->to($destination)->subject('Invitation à rejoindre '.$company.' sur O’CHANTIER'));

            return;
        }

        $this->sms->send($destination, $message);
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }

    private function initials(User $user): ?string
    {
        return mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)) ?: null;
    }
}
