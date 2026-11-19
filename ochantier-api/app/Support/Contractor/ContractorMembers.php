<?php

namespace App\Support\Contractor;

use App\Enums\ContractorMemberRole;
use App\Enums\ContractorMemberStatus;
use App\Enums\MessageChannel;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Contractor;
use App\Models\ContractorMember;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\PhoneNumber;
use App\Support\Sms\SmsGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * « Mon équipe » de l’entrepreneur : le titulaire invite ses collaborateurs par SMS ou e-mail et leur attribue un
 * rôle, dans la limite des places de sa formule (titulaire compris) et avec un abonnement actif. L’invité crée son
 * mot de passe avec « Mot de passe oublié » ; l’invitation, valable INVITATION_DAYS jours, est acceptée à sa première
 * connexion. Un utilisateur appartient à un seul compte : une personne qui a déjà son propre dossier d’entrepreneur
 * ou qui collabore avec un autre compte ne peut pas être invitée. Un collaborateur retiré perd l’accès tout de suite.
 */
class ContractorMembers
{
    public const INVITATION_DAYS = 7;

    public function __construct(
        private ContractorSubscriptions $subscriptions,
        private ContractorAccess $access,
        private ContractorNotifier $notifier,
        private AuthSessions $sessions,
        private SmsGateway $sms,
    ) {}

    /**
     * Écran « Mon équipe » : formule, places utilisées, titulaire et collaborateurs, rôles proposés.
     *
     * @return array<string, mixed>
     */
    public function summary(Contractor $contractor, User $viewer): array
    {
        $members = $contractor->members()->with('user')->where('status', '!=', ContractorMemberStatus::Revoque->value)->orderBy('created_at')->get();
        $seats = $this->subscriptions->seats($contractor);
        $used = 1 + $members->count();
        $subscribed = $this->subscriptions->isSubscribed($contractor);
        $owner = $contractor->user;

        return [
            'plan' => $this->subscriptions->current($contractor)?->plan->name ?? 'Solo',
            'seats' => $seats,
            'used' => $used,
            'usage_label' => $used.' / '.$seats.' utilisateur'.($seats > 1 ? 's' : ''),
            'can_invite' => $subscribed && $used < $seats,
            'hint' => match (true) {
                ! $subscribed => 'Formule Solo : seul le titulaire utilise le compte.',
                $used >= $seats => 'Limite de '.$seats.' utilisateurs atteinte pour votre formule.'.($seats < 20 ? ' Passez à Pro pour inviter jusqu’à 20 membres.' : ''),
                default => 'Invitez vos collaborateurs et attribuez-leur un rôle.',
            },
            'members' => [
                [
                    'id' => null,
                    'name' => $this->name($owner).($owner->is($viewer) ? ' (vous)' : ''),
                    'role' => ['value' => ContractorAccess::OWNER, 'label' => 'Titulaire'],
                    'contact' => PhoneNumber::of($owner)?->formatted() ?? $owner->email,
                    'status' => ['value' => 'titulaire', 'label' => 'Titulaire'],
                    'can_remove' => false,
                ],
                ...$members->map(fn (ContractorMember $member) => [
                    'id' => $member->id,
                    'name' => $this->name($member->user).($member->user->is($viewer) ? ' (vous)' : ''),
                    'role' => ['value' => $member->role->value, 'label' => $member->role->label()],
                    'contact' => PhoneNumber::of($member->user)?->formatted() ?? $member->user->email,
                    'status' => ['value' => $member->status->value, 'label' => $member->status->label()],
                    'can_remove' => true,
                ])->all(),
            ],
            'roles' => collect(ContractorMemberRole::cases())->map(fn (ContractorMemberRole $role) => ['value' => $role->value, 'label' => $role->label(), 'description' => $role->description()])->all(),
        ];
    }

    /**
     * « Envoyer l’invitation ».
     *
     * @param  array{first_name: string, last_name?: string|null, role: string, channel: string, phone?: PhoneNumber|null, email?: string|null}  $data
     */
    public function invite(Contractor $contractor, User $inviter, array $data): ContractorMember
    {
        $this->subscriptions->ensureSubscribed($contractor, 'Collaborateurs du compte');
        $seats = $this->subscriptions->seats($contractor);

        if (1 + $contractor->members()->where('status', '!=', ContractorMemberStatus::Revoque->value)->count() >= $seats) {
            throw ValidationException::withMessages(['role' => 'Limite de '.$seats.' utilisateurs atteinte pour votre formule.']);
        }

        $channel = MessageChannel::from($data['channel']);
        $phone = $channel === MessageChannel::Sms ? $data['phone'] : null;
        $email = $channel === MessageChannel::Email ? mb_strtolower(trim((string) $data['email'])) : null;
        $field = $phone ? 'phone_number' : 'email';
        $existing = User::findByContact(UserType::Contractor, $phone?->e164(), $email);

        if ($existing?->is($contractor->user)) {
            throw ValidationException::withMessages([$field => 'Vous ne pouvez pas vous inviter vous-même.']);
        }

        if ($existing && ($existing->contractor()->exists() || ContractorMember::query()->where('user_id', $existing->getKey())->where('contractor_id', '!=', $contractor->getKey())->where('status', '!=', ContractorMemberStatus::Revoque->value)->exists())) {
            throw ValidationException::withMessages([$field => ($phone ? 'Ce numéro' : 'Cette adresse e-mail').' est déjà utilisé par un autre compte O’CHANTIER Pro.']);
        }

        return DB::transaction(function () use ($contractor, $inviter, $data, $channel, $phone, $email, $existing, $field): ContractorMember {
            $user = $existing ?? User::query()->create([
                'user_type' => UserType::Contractor,
                'first_name' => trim($data['first_name']),
                'last_name' => trim((string) ($data['last_name'] ?? '')) ?: null,
                ...PhoneNumber::attributes($phone),
                'email' => $email,
                'status' => UserStatus::Actif,
            ]);

            $member = $contractor->members()->where('user_id', $user->getKey())->first();

            if ($member && $member->status !== ContractorMemberStatus::Revoque) {
                throw ValidationException::withMessages([$field => $this->name($user).' fait déjà partie de votre équipe.']);
            }

            $member = $contractor->members()->updateOrCreate(['user_id' => $user->getKey()], [
                'role' => ContractorMemberRole::from($data['role']),
                'status' => ContractorMemberStatus::Invite,
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

            $this->send($channel, $phone?->e164() ?? (string) $email, $contractor, $inviter, $member->role);

            return $member->load('user');
        });
    }

    public function changeRole(ContractorMember $member, string $role): ContractorMember
    {
        $member->update(['role' => ContractorMemberRole::from($role)]);
        $this->access->forget($member->user);

        return $member->load('user');
    }

    /**
     * Retire un collaborateur : accès révoqué, invitation annulée et sessions fermées.
     */
    public function remove(ContractorMember $member): void
    {
        DB::transaction(function () use ($member): void {
            $member->update(['status' => ContractorMemberStatus::Revoque, 'revoked_at' => now()]);
            $this->pendingInvitations($member->user)->update(['cancelled_at' => now()]);
            $this->sessions->revokeAll($member->user, 'access_revoked');
        });

        $this->access->forget($member->user);
    }

    /**
     * À la connexion de l’invité : son invitation valide devient un accès actif au compte.
     */
    public function acceptInvitations(User $user): int
    {
        $invitation = $this->pendingInvitations($user)->where('expires_at', '>', now())->latest('sent_at')->first();

        if (! $invitation) {
            return 0;
        }

        $members = DB::transaction(function () use ($user, $invitation) {
            $invitation->update(['accepted_at' => now()]);
            $members = ContractorMember::query()->with(['contractor.user', 'user'])->where('user_id', $user->getKey())->where('status', ContractorMemberStatus::Invite->value)->get();

            foreach ($members as $member) {
                $member->update(['status' => ContractorMemberStatus::Actif, 'joined_at' => now()]);
            }

            return $members;
        });

        foreach ($members as $member) {
            $this->notifier->notify($member->contractor, 'team.joined', 'Nouveau collaborateur',
                $this->name($user).' a rejoint votre compte en tant que '.mb_strtolower($member->role->label()).'.', ['type' => 'account_team', 'id' => $member->id], $member);
        }

        $this->access->forget($user);

        return $members->count();
    }

    public function findMember(Contractor $contractor, string $id): ContractorMember
    {
        $member = Str::isUuid($id) ? $contractor->members()->with('user')->where('status', '!=', ContractorMemberStatus::Revoque->value)->find($id) : null;

        abort_unless($member, 404, 'Collaborateur introuvable.');

        return $member;
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

    private function send(MessageChannel $channel, string $destination, Contractor $contractor, User $inviter, ContractorMemberRole $role): void
    {
        $message = 'O’CHANTIER : '.$this->name($inviter).' vous invite à rejoindre « '.$contractor->name.' » en tant que '.mb_strtolower($role->label())
            .'. Installez l’application O’CHANTIER Pro, puis créez votre mot de passe avec « Mot de passe oublié » et ce '
            .($channel === MessageChannel::Email ? 'e-mail' : 'numéro').' (invitation valable '.self::INVITATION_DAYS.' jours).';

        if ($channel === MessageChannel::Email) {
            Mail::raw($message, fn ($mail) => $mail->to($destination)->subject('Invitation à rejoindre '.$contractor->name.' sur O’CHANTIER Pro'));

            return;
        }

        $this->sms->send($destination, $message);
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name) ?: (PhoneNumber::of($user)?->formatted() ?? (string) $user->email);
    }
}
