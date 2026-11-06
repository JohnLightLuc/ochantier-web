<?php

namespace App\Support\Commercial;

use App\Enums\CommercialProfileStatus;
use App\Enums\Priority;
use App\Enums\ProspectStatus;
use App\Models\CommercialAdjustment;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Models\Notification;
use App\Models\Prospect;
use App\Models\User;
use App\Models\Worker;
use App\Support\Notifications\NotificationInbox;
use Carbon\CarbonImmutable;

/**
 * Notifications de l’application Commercial : décisions du Back office sur les dossiers, versements des
 * commissions, relances conseillées, objectif et classement. Inscrites dans le centre de notifications, puis
 * envoyées en push et, pour les corrections et les versements, par SMS selon les préférences.
 */
class CommercialNotifier
{
    /**
     * Événement => onglet (dossiers, paiements, infos), alerte des préférences, priorité, SMS et bouton à afficher.
     *
     * @var array<string, array{tab: string, alert: string|null, priority: Priority, sms: bool, cta: string}>
     */
    public const EVENTS = [
        'worker.correction' => ['tab' => 'dossiers', 'alert' => 'corrections', 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Corriger le dossier'],
        'worker.validated' => ['tab' => 'dossiers', 'alert' => 'dossiers', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir le dossier'],
        'worker.refused' => ['tab' => 'dossiers', 'alert' => 'dossiers', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir le dossier'],
        'worker.updated' => ['tab' => 'dossiers', 'alert' => 'dossiers', 'priority' => Priority::Basse, 'sms' => false, 'cta' => 'Voir le dossier'],
        'payout.paid' => ['tab' => 'paiements', 'alert' => 'paiements', 'priority' => Priority::Normale, 'sms' => true, 'cta' => 'Voir mes gains'],
        'prospect.follow_up' => ['tab' => 'dossiers', 'alert' => 'relances', 'priority' => Priority::Basse, 'sms' => false, 'cta' => 'Voir le prospect'],
        'objective.reminder' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Basse, 'sms' => false, 'cta' => 'Voir l’accueil'],
        'ranking.updated' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Basse, 'sms' => false, 'cta' => ''],
        'account.suspended' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => ''],
        'account.reactivated' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => ''],
        'account.terminated' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => ''],
        'zone.accepted' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => ''],
        'zone.refused' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => ''],
        'ranking.published' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir le classement'],
        'adjustment.approved' => ['tab' => 'paiements', 'alert' => 'paiements', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir mes gains'],
        'dispute.updated' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Haute, 'sms' => false, 'cta' => ''],
        'claim.updated' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => ''],
        'objective.set' => ['tab' => 'infos', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => ''],
    ];

    /**
     * Onglets du centre de notifications.
     *
     * @var array<string, string>
     */
    public const TABS = ['toutes' => 'Toutes', 'non_lues' => 'Non lues', 'dossiers' => 'Dossiers', 'paiements' => 'Paiements'];

    /**
     * Jours sans nouvelles avant de conseiller une relance.
     */
    public const FOLLOW_UP_DAYS = 7;

    public function __construct(private NotificationInbox $inbox, private CommercialPerformance $performance, private CommercialEarnings $earnings) {}

    /**
     * @param  list<string>  $reasons
     */
    public function correctionRequested(Worker $worker, array $reasons): void
    {
        $this->notify($worker, 'worker.correction', 'Correction demandée', $this->name($worker).' — '.implode(' ; ', $reasons).'.', 'worker.correction:'.$worker->id.':'.now()->timestamp);
    }

    /**
     * Dossier modifié par le Back Office (ouvrier dont le commercial est le référent).
     *
     * @param  list<string>  $changes  Libellés des informations modifiées.
     */
    public function updatedByBackOffice(Worker $worker, array $changes): void
    {
        $this->notify($worker, 'worker.updated', 'Dossier mis à jour', $this->name($worker).' : '.implode(', ', $changes).' mis à jour par le Back office.', 'worker.updated:'.$worker->id.':'.now()->timestamp);
    }

    public function validated(Worker $worker, ?int $commission): void
    {
        $transferred = $worker->origin_commercial_id !== $worker->current_commercial_id;

        $this->notify($worker, 'worker.validated', 'Dossier validé',
            $this->name($worker).' a été validé(e).'.($commission && ! $transferred ? ' Commission de '.$this->earnings->money($commission).' confirmée.' : ''), 'worker.validated:'.$worker->id);

        // Après un transfert, la commission reste au recruteur : lui seul en est informé.
        if ($commission && $transferred && ($recruiter = $worker->originCommercial?->user)) {
            $this->inbox->send($recruiter, 'worker.validated', self::EVENTS['worker.validated'], 'Commission confirmée',
                $this->name($worker).' a été validé(e). Commission de '.$this->earnings->money($commission).' confirmée.', ['target' => ['type' => 'earnings', 'id' => null], 'cta' => 'Voir mes gains'], $worker, 'worker.validated.commission:'.$worker->id);
        }
    }

    public function refused(Worker $worker, string $reason): void
    {
        $this->notify($worker, 'worker.refused', 'Dossier refusé', $this->name($worker).' — '.$reason.'.', 'worker.refused:'.$worker->id);
    }

    public function payoutPaid(CommercialPayout $payout): void
    {
        $user = $payout->commercial->user;

        $this->inbox->send($user, 'payout.paid', self::EVENTS['payout.paid'], 'Paiement reçu',
            $this->earnings->money($payout->total_amount).' versés sur votre compte Mobile Money.',
            ['target' => ['type' => 'earnings', 'id' => null, 'month' => CarbonImmutable::parse($payout->period_start)->format('Y-m')]], $payout, 'payout.paid:'.$payout->id);
    }

    /**
     * Décision du Back Office sur le compte (suspension, réactivation, fin de contrat).
     */
    public function accountEvent(CommercialProfile $commercial, string $event, string $title, string $body): void
    {
        $this->inbox->send($commercial->user, $event, self::EVENTS[$event], $title, $body, ['target' => ['type' => 'profile', 'id' => null]], $commercial, $event.':'.$commercial->id.':'.now()->timestamp);
    }

    public function rankingPublished(CommercialProfile $commercial, string $body, string $rankingId): void
    {
        $this->inbox->send($commercial->user, 'ranking.published', self::EVENTS['ranking.published'], 'Classement publié', $body,
            ['target' => ['type' => 'ranking', 'id' => null], 'cta' => self::EVENTS['ranking.published']['cta']], $commercial, 'ranking.published:'.$rankingId);
    }

    public function adjustmentApproved(CommercialProfile $commercial, CommercialAdjustment $adjustment, string $body): void
    {
        $this->inbox->send($commercial->user, 'adjustment.approved', self::EVENTS['adjustment.approved'], 'Vos gains ont été ajustés', $body,
            ['target' => ['type' => 'earnings', 'id' => null, 'month' => $adjustment->granted_on->format('Y-m')], 'cta' => self::EVENTS['adjustment.approved']['cta']], $adjustment, 'adjustment.approved:'.$adjustment->id);
    }

    /**
     * Rappels quotidiens : prospects sans nouvelles depuis FOLLOW_UP_DAYS jours ; le lundi, objectif et classement.
     *
     * @return array{follow_ups: int, objectives: int, rankings: int}
     */
    public function reminders(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $week = $today->startOfWeek()->toDateString();
        $counts = ['follow_ups' => 0, 'objectives' => 0, 'rankings' => 0];

        Prospect::query()
            ->whereNotIn('status', [ProspectStatus::Converti, ProspectStatus::Abandonne])
            ->where('updated_at', '<=', $today->subDays(self::FOLLOW_UP_DAYS)->endOfDay())
            ->whereHas('commercial', fn ($query) => $query->where('status', CommercialProfileStatus::Actif))
            ->with('commercial.user')
            ->each(function (Prospect $prospect) use ($week, &$counts): void {
                $days = (int) CarbonImmutable::parse($prospect->updated_at)->startOfDay()->diffInDays(CarbonImmutable::today());
                $sent = $this->inbox->send($prospect->commercial->user, 'prospect.follow_up', self::EVENTS['prospect.follow_up'], 'Relance conseillée',
                    'Le dossier de '.trim($prospect->first_name.' '.$prospect->last_name).' est sans nouvelles depuis '.$days.' jours.',
                    ['target' => ['type' => 'prospect', 'id' => $prospect->id], 'cta' => self::EVENTS['prospect.follow_up']['cta']], $prospect, 'prospect.follow_up:'.$prospect->id.':'.$week);
                $counts['follow_ups'] += (int) $sent;
            });

        if (! $today->isMonday()) {
            return $counts;
        }

        CommercialProfile::query()->where('status', CommercialProfileStatus::Actif)->with('user')->each(function (CommercialProfile $commercial) use ($week, &$counts): void {
            $objective = $this->performance->objective($commercial);

            if ($objective && $objective['remaining'] > 0) {
                $counts['objectives'] += (int) $this->inbox->send($commercial->user, 'objective.reminder', self::EVENTS['objective.reminder'], 'Objectif du mois', $objective['message'],
                    ['target' => ['type' => 'home', 'id' => null], 'cta' => self::EVENTS['objective.reminder']['cta']], $commercial, 'objective.reminder:'.$commercial->id.':'.$week);
            }

            $ranking = $this->performance->ranking($commercial);

            if ($ranking['label']) {
                $counts['rankings'] += (int) $this->inbox->send($commercial->user, 'ranking.updated', self::EVENTS['ranking.updated'], 'Classement mis à jour',
                    'Vous êtes '.$ranking['label'].' ce mois-ci.', ['target' => ['type' => 'ranking', 'id' => null], 'cta' => ''], $commercial, 'ranking.updated:'.$commercial->id.':'.$week);
            }
        });

        return $counts;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function center(User $user, string $filter = 'toutes'): array
    {
        return $this->inbox->center($user, $filter, self::TABS,
            fn (Notification $notification, string $tab) => match ($tab) {
                'toutes' => true,
                'non_lues' => $notification->read_at === null,
                default => ($notification->data['tab'] ?? null) === $tab,
            },
            fn (Notification $notification) => ['cta' => ($notification->data['cta'] ?? self::EVENTS[$notification->event_code]['cta'] ?? '') ?: null],
            ['toutes', 'non_lues'],
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

    private function notify(Worker $worker, string $event, string $title, string $body, string $dedup): void
    {
        $user = $worker->currentCommercial?->user;

        if ($user) {
            $this->inbox->send($user, $event, self::EVENTS[$event], $title, $body,
                ['target' => ['type' => 'worker', 'id' => $worker->id, 'prospect_id' => $worker->prospect_id], 'cta' => self::EVENTS[$event]['cta']], $worker, $dedup);
        }
    }

    private function name(Worker $worker): string
    {
        return trim($worker->first_name.' '.$worker->last_name);
    }
}
