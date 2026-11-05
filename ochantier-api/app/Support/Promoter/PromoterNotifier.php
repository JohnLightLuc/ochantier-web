<?php

namespace App\Support\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\PaymentInstallmentStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\Priority;
use App\Enums\ProposalStatus;
use App\Models\Notification;
use App\Models\OrganizationMember;
use App\Models\PaymentInstallment;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\PromoterProfile;
use App\Models\Proposal;
use App\Models\Referral;
use App\Models\Replacement;
use App\Models\Site;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Notifications\NotificationInbox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Notifications de l’application Promoteur : chaque événement est inscrit dans le centre de notifications (in_app)
 * des utilisateurs du compte qui ont le droit lié (le propriétaire reçoit tout ; le technicien seulement pour ses
 * chantiers), puis envoyé en push et, pour les alertes importantes, par SMS, selon les préférences de chacun.
 */
class PromoterNotifier
{
    /**
     * Événement => onglet du centre (offres, paiements, chantiers), alerte des préférences, droit requis, priorité et
     * envoi par SMS.
     *
     * @var array<string, array{tab: string, alert: string|null, ability: string, priority: Priority, sms: bool}>
     */
    public const EVENTS = [
        'proposal.received' => ['tab' => 'offres', 'alert' => 'offres', 'ability' => 'proposals.decide', 'priority' => Priority::Haute, 'sms' => true],
        'replacement.proposed' => ['tab' => 'offres', 'alert' => 'offres', 'ability' => 'replacements.manage', 'priority' => Priority::Haute, 'sms' => false],
        'payment.succeeded' => ['tab' => 'paiements', 'alert' => 'paiements', 'ability' => 'payments.manage', 'priority' => Priority::Normale, 'sms' => false],
        'payment.due' => ['tab' => 'paiements', 'alert' => 'paiements', 'ability' => 'payments.manage', 'priority' => Priority::Normale, 'sms' => false],
        'payment.late' => ['tab' => 'paiements', 'alert' => 'paiements', 'ability' => 'payments.manage', 'priority' => Priority::Haute, 'sms' => true],
        'subscription.invoice' => ['tab' => 'paiements', 'alert' => 'paiements', 'ability' => 'account.manage', 'priority' => Priority::Haute, 'sms' => false],
        'site.confirmed' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'sites.view', 'priority' => Priority::Normale, 'sms' => false],
        'site.started' => ['tab' => 'chantiers', 'alert' => 'pointages', 'ability' => 'sites.view', 'priority' => Priority::Normale, 'sms' => false],
        'site.ended' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'sites.view', 'priority' => Priority::Normale, 'sms' => false],
        'site.suspended' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'sites.view', 'priority' => Priority::Haute, 'sms' => false],
        'site.resumed' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'sites.view', 'priority' => Priority::Normale, 'sms' => false],
        'site.cancelled' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'sites.view', 'priority' => Priority::Haute, 'sms' => false],
        'report.available' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'finance.view', 'priority' => Priority::Normale, 'sms' => false],
        'attendance.pending' => ['tab' => 'chantiers', 'alert' => 'pointages', 'ability' => 'attendance.manage', 'priority' => Priority::Haute, 'sms' => false],
        'referral.registered' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Basse, 'sms' => false],
        'team.joined' => ['tab' => 'chantiers', 'alert' => null, 'ability' => 'team.manage', 'priority' => Priority::Basse, 'sms' => false],
        'account.approved' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Normale, 'sms' => false],
        'account.corrections' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Haute, 'sms' => true],
        'account.rejected' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Haute, 'sms' => true],
        'account.suspended' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Haute, 'sms' => true],
        'account.reactivated' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Normale, 'sms' => true],
        'admin.message' => ['tab' => 'compte', 'alert' => null, 'ability' => 'account.manage', 'priority' => Priority::Normale, 'sms' => false],
    ];

    /**
     * Onglets du centre de notifications.
     *
     * @var array<string, string>
     */
    public const TABS = ['all' => 'Toutes', 'unread' => 'Non lues', 'offres' => 'Offres', 'paiements' => 'Paiements', 'chantiers' => 'Chantiers'];

    public function __construct(private NotificationInbox $inbox, private PromoterPayments $payments) {}

    public function proposalReceived(Proposal $proposal): void
    {
        $site = $proposal->site;
        $count = $proposal->proposed_count;

        $this->notify($site->promoterProfile, 'proposal.received', 'Ouvriers trouvés · '.$site->label,
            ($proposal->is_partial ? $count.' ouvrier'.($count > 1 ? 's' : '').' sur '.$proposal->requested_count.' demandés' : $count.' ouvrier'.($count > 1 ? 's' : '').' proposé'.($count > 1 ? 's' : ''))
            .'. Acompte à régler : '.$this->payments->money($proposal->deposit_amount).'.',
            $site, ['type' => 'reservation', 'id' => $proposal->offer_id, 'proposal_id' => $proposal->id], $proposal, 'proposal.received:'.$proposal->id);
    }

    public function replacementsProposed(Replacement $replacement, int $count): void
    {
        $site = $replacement->mission->site;
        $worker = $replacement->originalAssignment->worker;

        $this->notify($site->promoterProfile, 'replacement.proposed', 'Remplaçants proposés · '.$site->label,
            $count.' remplaçant'.($count > 1 ? 's' : '').' pour '.trim($worker->first_name.' '.$worker->last_name).'. Choisissez votre remplaçant.',
            $site, ['type' => 'replacement', 'id' => $replacement->id], $replacement, 'replacement.proposed:'.$replacement->id.':'.$replacement->updated_at?->timestamp);
    }

    public function paymentSucceeded(PaymentTransaction $transaction, PromoterProfile $profile, ?Site $site): void
    {
        $this->notify($profile, 'payment.succeeded', 'Paiement confirmé · '.$this->payments->money($transaction->amount + $transaction->fees_amount),
            (string) ($transaction->metadata['done_text'] ?? $transaction->metadata['label'] ?? 'Paiement reçu.'),
            $site, ['type' => 'transaction', 'id' => $transaction->id], $transaction, 'payment.succeeded:'.$transaction->id);
    }

    public function siteConfirmed(Site $site): void
    {
        $this->notify($site->promoterProfile, 'site.confirmed', 'Équipe confirmée · '.$site->label, 'Acompte réglé : l’équipe est réservée pour votre chantier.',
            $site, ['type' => 'site', 'id' => $site->id], $site, 'site.confirmed:'.$site->id);
    }

    public function siteStarted(Site $site): void
    {
        $this->notify($site->promoterProfile, 'site.started', 'Chantier démarré · '.$site->label, 'Première arrivée pointée : le chantier est en cours.',
            $site, ['type' => 'site', 'id' => $site->id], $site, 'site.started:'.$site->id);
    }

    /**
     * Fin du chantier, et rapport de fin de chantier pour un compte abonné.
     */
    public function siteEnded(Site $site, bool $reportAvailable): void
    {
        $this->notify($site->promoterProfile, 'site.ended', 'Chantier terminé · '.$site->label, 'Notez votre équipe et chaque ouvrier.',
            $site, ['type' => 'site', 'id' => $site->id], $site, 'site.ended:'.$site->id);

        if ($reportAvailable) {
            $this->notify($site->promoterProfile, 'report.available', 'Rapport de fin de chantier disponible', 'Le bilan complet de '.$site->label.' est prêt à télécharger.',
                $site, ['type' => 'report', 'id' => $site->id], $site, 'report.available:'.$site->id);
        }
    }

    public function invoiceIssued(SubscriptionInvoice $invoice): void
    {
        $this->notify($invoice->subscription->promoterProfile, 'subscription.invoice', 'Renouvellement de votre abonnement',
            $invoice->label.' : '.$this->payments->money($invoice->amount).' à régler.',
            null, ['type' => 'subscription_invoice', 'id' => $invoice->id], $invoice, 'subscription.invoice:'.$invoice->id);
    }

    public function referralRegistered(Referral $referral): void
    {
        $user = $referral->referredUser;

        $this->notify($referral->referrerProfile, 'referral.registered', 'Votre invitation a porté ses fruits',
            (trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: 'Un proche').' s’est inscrit sur O’CHANTIER grâce à votre invitation.',
            null, ['type' => 'referrals', 'id' => null], $referral, 'referral.registered:'.$referral->id);
    }

    public function teamJoined(OrganizationMember $member): void
    {
        $profile = $member->organization->ownerUser->promoterProfile;
        $user = $member->user;

        if ($profile) {
            $this->notify($profile, 'team.joined', trim($user->first_name.' '.$user->last_name).' a rejoint votre équipe',
                'Rôle : '.PromoterAccess::ROLE_LABELS[$member->role->value].'.', null, ['type' => 'team', 'id' => $member->id], $member, 'team.joined:'.$member->id.':'.$member->joined_at?->timestamp);
        }
    }

    /**
     * Décision du Back Office sur le compte (vérification, suspension) ou message de l’équipe O’CHANTIER : envoyé au
     * propriétaire et aux administrateurs du compte, avec un lien vers le profil.
     */
    public function accountEvent(PromoterProfile $profile, string $event, string $title, string $body): void
    {
        $this->notify($profile, $event, $title, $body, null, ['type' => 'profile', 'id' => null], $profile);
    }

    /**
     * Rappels quotidiens : paiements à régler demain, paiements en retard, journées de pointage à valider.
     *
     * @return array{due: int, late: int, attendance: int}
     */
    public function reminders(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['due' => 0, 'late' => 0, 'attendance' => 0];

        foreach ($this->unpaid($today->addDay(), '=') as $row) {
            $counts['due'] += $this->notify($row['site']->promoterProfile, 'payment.due', 'Paiement à régler demain · '.$row['site']->label,
                $this->payments->money($row['amount']).' à régler le '.$this->date($row['due']).'.', $row['site'], ['type' => 'payments', 'id' => null], $row['site'],
                'payment.due:'.$row['site']->id.':'.$row['due']->toDateString()) > 0 ? 1 : 0;
        }

        foreach ($this->unpaid($today, '<') as $row) {
            $counts['late'] += $this->notify($row['site']->promoterProfile, 'payment.late', 'Paiement en retard · '.$row['site']->label,
                $this->payments->money($row['amount']).' en retard depuis le '.$this->date($row['due']).'. Réglez-le pour éviter la suspension du chantier.', $row['site'], ['type' => 'payments', 'id' => null, 'filter' => 'en_retard'], $row['site'],
                'payment.late:'.$row['site']->id.':'.$row['due']->toDateString()) > 0 ? 1 : 0;
        }

        $pending = WorkDay::query()
            ->whereDate('work_date', $today->subDay())
            ->whereNull('validated_at')
            ->with('mission.site.promoterProfile')
            ->get()
            ->groupBy('mission.site_id');

        foreach ($pending as $days) {
            $site = $days->first()->mission->site;
            $counts['attendance'] += $this->notify($site->promoterProfile, 'attendance.pending', 'Journée à valider · '.$site->label,
                'Validez le pointage du '.$this->date($today->subDay()).' ('.$days->count().' ouvrier'.($days->count() > 1 ? 's' : '').').', $site,
                ['type' => 'attendance', 'id' => $site->id, 'date' => $today->subDay()->toDateString()], $site, 'attendance.pending:'.$site->id.':'.$today->subDay()->toDateString()) > 0 ? 1 : 0;
        }

        return $counts;
    }

    /**
     * Centre de notifications de l’utilisateur : groupes Aujourd’hui / Cette semaine / Plus anciennes.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function center(User $user, string $filter = 'all'): array
    {
        $proposals = Proposal::query()
            ->whereIn('id', Notification::query()->where('recipient_id', $user->getKey())->where('event_code', 'proposal.received')->select('related_id'))
            ->get()
            ->keyBy('id');

        return $this->inbox->center($user, $filter, self::TABS,
            fn (Notification $notification, string $tab) => match ($tab) {
                'all' => true,
                'unread' => $notification->read_at === null,
                default => ($notification->data['tab'] ?? null) === $tab,
            },
            fn (Notification $notification) => $this->extra($notification, $proposals),
            ['all', 'unread'],
        );
    }

    public function unreadCount(User $user): int
    {
        return $this->inbox->unreadCount($user);
    }

    public function markRead(User $user, ?string $id = null): int
    {
        return $this->inbox->markRead($user, $id);
    }

    /**
     * Inscrit l’événement pour chaque destinataire du compte, puis l’envoie selon ses préférences. $dedup évite les
     * doublons (un même rappel, un même événement). Renvoie le nombre de destinataires notifiés.
     *
     * @param  array<string, mixed>  $target  Écran à ouvrir dans l’application.
     */
    public function notify(PromoterProfile $profile, string $event, string $title, string $body, ?Site $site, array $target, ?Model $related = null, ?string $dedup = null): int
    {
        $definition = self::EVENTS[$event];
        $data = ['target' => $target, 'site' => $site ? ['id' => $site->id, 'title' => $site->label] : null];

        return $this->recipients($profile, $definition['ability'], $site)
            ->filter(fn (User $user) => $this->inbox->send($user, $event, $definition, $title, $body, $data, $related, $dedup))
            ->count();
    }

    /**
     * Propriétaire et collaborateurs actifs qui ont le droit ; le technicien seulement pour ses chantiers.
     *
     * @return Collection<int, User>
     */
    private function recipients(PromoterProfile $profile, string $ability, ?Site $site): Collection
    {
        $roles = PromoterAccess::ABILITIES[$ability];
        $members = OrganizationMember::query()
            ->whereHas('organization', fn ($query) => $query->where('owner_user_id', $profile->user_id))
            ->where('status', OrganizationMemberStatus::Actif)
            ->whereIn('role', array_diff($roles, [PromoterAccess::OWNER]))
            ->with('user')
            ->get()
            ->reject(fn (OrganizationMember $member) => $member->role->value === 'responsable_chantier' && (! $site || $site->responsible_user_id !== $member->user_id));

        return collect([$profile->user])->concat($members->pluck('user'))->unique('id')->values();
    }

    /**
     * Montants impayés par chantier et par date d’échéance (= demain, ou < aujourd’hui pour les retards), échéances
     * et versements de paiement flexible confondus ; une échéance couverte par un paiement flexible en cours compte
     * par ses versements.
     *
     * @return list<array{site: Site, due: CarbonImmutable, amount: int}>
     */
    private function unpaid(CarbonImmutable $date, string $operator): array
    {
        $open = PaymentInstallment::query()->where('status', PaymentInstallmentStatus::APayer)->whereDate('due_date', $operator, $date)->with('site.promoterProfile')->get();
        $covered = PaymentInstallment::query()->where('status', PaymentInstallmentStatus::APayer)->pluck('schedule_ids')->flatten()->unique()->values()->all();
        $schedules = PaymentSchedule::query()
            ->whereNotIn('status', [PaymentScheduleStatus::Payee, PaymentScheduleStatus::Annulee, PaymentScheduleStatus::Remboursee])
            ->whereColumn('amount_paid', '<', 'amount_due')
            ->whereNotNull('due_date')
            ->whereDate('due_date', $operator, $date)
            ->whereKeyNot($covered)
            ->with('mission.site.promoterProfile')
            ->get();

        $rows = $schedules->map(fn (PaymentSchedule $schedule) => ['site' => $schedule->mission->site, 'due' => CarbonImmutable::parse($schedule->due_date), 'amount' => $schedule->amount_due - $schedule->amount_paid])
            ->concat($open->map(fn (PaymentInstallment $installment) => ['site' => $installment->site, 'due' => CarbonImmutable::parse($installment->due_date), 'amount' => $installment->amount]));

        return $rows->groupBy(fn (array $row) => $row['site']->id.'|'.$row['due']->toDateString())
            ->map(fn (Collection $group) => ['site' => $group->first()['site'], 'due' => $group->first()['due'], 'amount' => (int) $group->sum('amount')])
            ->values()
            ->all();
    }

    /**
     * Chantier, tag (« Acompte réglé », « Action requise ») et actions d’une notification.
     *
     * @param  Collection<string, Proposal>  $proposals
     * @return array<string, mixed>
     */
    private function extra(Notification $notification, Collection $proposals): array
    {
        $proposal = $notification->event_code === 'proposal.received' ? $proposals->get($notification->related_id) : null;
        $awaiting = $proposal && in_array($proposal->status, [ProposalStatus::AValider, ProposalStatus::Acceptee], true) && ! $proposal->suggestions()->whereHas('need.missions')->exists();
        $paid = $proposal && $proposal->status === ProposalStatus::Acceptee && ! $awaiting;

        return [
            'site' => $notification->data['site'] ?? null,
            'tag' => match (true) {
                $paid => ['label' => 'Acompte réglé', 'tone' => 'positive'],
                $notification->event_code === 'payment.late' => ['label' => 'Action requise', 'tone' => 'negative'],
                default => null,
            },
            'actions' => $awaiting ? ['voir_offre', 'payer_acompte'] : [],
        ];
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
