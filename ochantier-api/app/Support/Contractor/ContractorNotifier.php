<?php

namespace App\Support\Contractor;

use App\Enums\ContractorStatus;
use App\Enums\Priority;
use App\Models\Contractor;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\Site;
use App\Models\User;
use App\Support\Notifications\NotificationInbox;
use App\Support\Promoter\ContractorOffers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Notifications de l’application Entrepreneurs et Techniciens : décisions sur le dossier, nouvelles opportunités
 * compatibles avec le profil, suivi des propositions de devis. Inscrites dans le centre de notifications, puis
 * envoyées en push et, pour les décisions importantes, par SMS selon les préférences.
 */
class ContractorNotifier
{
    /**
     * Événement => onglet, alerte des préférences, priorité, SMS et bouton à afficher.
     *
     * @var array<string, array{tab: string, alert: string|null, priority: Priority, sms: bool, cta: string}>
     */
    public const EVENTS = [
        'profile.submitted' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir mon profil'],
        'account.approved' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir les opportunités'],
        'account.corrections' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Corriger mon dossier'],
        'account.rejected' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Corriger mon dossier'],
        'account.suspended' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Contacter l’assistance'],
        'account.reactivated' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => ''],
        'opportunity.quote' => ['tab' => 'opportunites', 'alert' => 'opportunites', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir la demande'],
        'opportunity.offer' => ['tab' => 'opportunites', 'alert' => 'opportunites', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir l’offre'],
        'proposal.ordered' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir la mission'],
        'proposal.not_retained' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir la proposition'],
        'visit.reschedule_accepted' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir la mission'],
        'visit.reschedule_refused' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir la mission'],
        'quote.received' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir le devis'],
        'quote.reminder' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir la mission'],
        'offer.chosen' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir mon offre'],
        'offer.attributed' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Ouvrir le chantier'],
        'offer.not_retained' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir mon offre'],
        'team.accepted' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir l’équipe'],
        'team.refused' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir l’équipe'],
        'staffing.offer' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir l’offre'],
        'staffing.paid' => ['tab' => 'paiements', 'alert' => 'paiements', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir l’équipe'],
        'attendance.pending' => ['tab' => 'chantiers', 'alert' => 'pointages', 'priority' => Priority::Haute, 'sms' => false, 'cta' => 'Pointer l’équipe'],
        'site.ended' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Noter l’équipe'],
        'subscription.paid' => ['tab' => 'compte', 'alert' => 'abonnement', 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir l’abonnement'],
        'subscription.renewal' => ['tab' => 'compte', 'alert' => 'abonnement', 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Renouveler'],
        'subscription.expired' => ['tab' => 'compte', 'alert' => 'abonnement', 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Renouveler'],
        'team.joined' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Basse, 'sms' => false, 'cta' => 'Voir mon équipe'],
        'payment.received' => ['tab' => 'paiements', 'alert' => 'paiements', 'priority' => Priority::Normale, 'sms' => true, 'cta' => 'Voir les paiements'],
    ];

    /**
     * Onglets du centre de notifications (catégories de la maquette).
     *
     * @var array<string, string>
     */
    public const TABS = [
        'toutes' => 'Toutes',
        'non_lues' => 'Non lues',
        'nouvelles_demandes' => 'Nouvelles demandes',
        'offres_acceptees' => 'Offres acceptées',
        'offres_refusees' => 'Offres refusées',
        'paiements' => 'Paiements',
        'rendez_vous' => 'Rendez-vous et dates',
        'chantiers' => 'Chantiers et équipe',
        'documents' => 'Documents',
        'abonnement' => 'Abonnement',
    ];

    /**
     * Catégorie de chaque événement (onglet du centre de notifications).
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'opportunity.quote' => 'nouvelles_demandes',
        'opportunity.offer' => 'nouvelles_demandes',
        'proposal.ordered' => 'offres_acceptees',
        'offer.chosen' => 'offres_acceptees',
        'offer.attributed' => 'offres_acceptees',
        'proposal.not_retained' => 'offres_refusees',
        'offer.not_retained' => 'offres_refusees',
        'payment.received' => 'paiements',
        'staffing.paid' => 'paiements',
        'visit.reschedule_accepted' => 'rendez_vous',
        'visit.reschedule_refused' => 'rendez_vous',
        'staffing.offer' => 'chantiers',
        'team.accepted' => 'chantiers',
        'team.refused' => 'chantiers',
        'team.joined' => 'chantiers',
        'attendance.pending' => 'chantiers',
        'site.ended' => 'chantiers',
        'quote.received' => 'documents',
        'quote.reminder' => 'rendez_vous',
        'profile.submitted' => 'documents',
        'account.approved' => 'documents',
        'account.corrections' => 'documents',
        'account.rejected' => 'documents',
        'account.suspended' => 'documents',
        'account.reactivated' => 'documents',
        'subscription.paid' => 'abonnement',
        'subscription.renewal' => 'abonnement',
        'subscription.expired' => 'abonnement',
    ];

    /**
     * Collaborateurs prévenus en plus du titulaire, par catégorie.
     *
     * @var array<string, list<string>>
     */
    private const MEMBER_CATEGORIES = [
        'chantiers' => ['chef_chantier'],
        'rendez_vous' => ['chef_chantier'],
        'paiements' => ['comptable'],
        'abonnement' => ['comptable'],
    ];

    public function __construct(private NotificationInbox $inbox) {}

    /**
     * Notifie le titulaire du compte et, selon la catégorie, les collaborateurs concernés (chef de chantier pour les
     * chantiers et l’équipe, comptable pour les paiements et l’abonnement).
     */
    public function notify(Contractor $contractor, string $event, string $title, string $body, array $target = [], mixed $related = null, ?string $dedup = null): void
    {
        $roles = self::MEMBER_CATEGORIES[self::CATEGORIES[$event] ?? ''] ?? [];
        $recipients = collect([$contractor->user])
            ->concat($roles === [] ? [] : $contractor->members()->with('user')->where('status', 'actif')->whereIn('role', $roles)->get()->pluck('user'))
            ->filter();

        foreach ($recipients as $user) {
            $this->inbox->send($user, $event, self::EVENTS[$event], $title, $body,
                ['target' => $target ?: ['type' => 'profile', 'id' => $contractor->id], 'cta' => self::EVENTS[$event]['cta'], 'category' => self::CATEGORIES[$event] ?? null], $related ?? $contractor, $dedup);
        }
    }

    /**
     * Centre de notifications : groupes par période (Aujourd’hui, Cette semaine, Plus anciennes), filtrés par onglet.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function center(User $user, string $filter = 'toutes'): array
    {
        return $this->inbox->center($user, $filter, self::TABS,
            fn (Notification $notification, string $tab) => match ($tab) {
                'toutes' => true,
                'non_lues' => $notification->read_at === null,
                default => (self::CATEGORIES[$notification->event_code] ?? null) === $tab,
            },
            fn (Notification $notification) => [
                'cta' => ($notification->data['cta'] ?? self::EVENTS[$notification->event_code]['cta'] ?? '') ?: null,
                'category' => ['value' => $category = self::CATEGORIES[$notification->event_code] ?? 'documents', 'label' => self::TABS[$category]],
            ],
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

    /**
     * Échéances du forfait d’un entrepreneur réglées par le promoteur : l’entrepreneur est prévenu (part nette).
     *
     * @param  Collection<int, PaymentSchedule>  $schedules
     */
    public function paymentReceived(Collection $schedules): void
    {
        foreach ($schedules->groupBy('mission_id') as $group) {
            $mission = Mission::query()->with(['site', 'need.offer.contractorBid.contractor.user', 'need.trade'])->find($group->first()->mission_id);
            $bid = $mission?->need?->offer?->contractorBid;

            if (! $bid || $mission->need->trade?->code !== ContractorOffers::TRADE) {
                continue;
            }

            $snapshot = $mission->pricingSnapshot;
            $net = (int) round($group->sum('amount_due') * ($snapshot && $snapshot->total_promoter_amount > 0 ? $snapshot->workers_base_amount / $snapshot->total_promoter_amount : 1));

            $this->notify($bid->contractor, 'payment.received', 'Paiement reçu',
                $mission->site->label.' : échéance de '.number_format($net, 0, ',', "\u{00A0}")."\u{00A0}FCFA (part nette) payée par le promoteur.",
                ['type' => 'site_payments', 'id' => $mission->site_id], $mission);
        }
    }

    /**
     * Événement d’une proposition de devis, pour son entrepreneur (les anciennes offres d’ouvriers sont ignorées).
     */
    public function proposalEvent(QuoteBid $bid, string $event, string $title, string $body): void
    {
        if ($bid->contractor) {
            $this->notify($bid->contractor, $event, $title, $body, ['type' => 'quote_proposal', 'id' => $bid->id], $bid);
        }
    }

    /**
     * Nouvelle demande de devis : chaque entrepreneur actif qui exerce un des métiers demandés dans la ville du
     * chantier est prévenu une fois, pour le métier qui le concerne.
     */
    public function newQuoteRequest(QuoteRequest $request): void
    {
        $request->loadMissing(['site.commune', 'trades.trade']);
        $site = $request->site;

        foreach ($request->trades as $line) {
            foreach ($this->candidates($site, [$line->trade_id]) as $contractor) {
                $this->notify($contractor, 'opportunity.quote', 'Nouvelle demande de devis · '.$line->trade->label,
                    $site->label.' ('.$this->place($site).') : répondez avant le '.$request->response_deadline?->format('d/m/Y à H:i').'.',
                    ['type' => 'quote_opportunity', 'id' => $line->id], $line, 'opportunity.quote:'.$line->id);
            }
        }
    }

    /**
     * Nouvelle offre contractuelle : entrepreneurs actifs de la ville du chantier (et des métiers de sa demande de
     * devis, s’il y en a eu une).
     *
     * @param  list<string>  $tradeIds
     */
    public function newContractOffer(Offer $offer, array $tradeIds = []): void
    {
        $offer->loadMissing('site.commune');
        $site = $offer->site;

        foreach ($this->candidates($site, $tradeIds) as $contractor) {
            $this->notify($contractor, 'opportunity.offer', 'Nouvelle offre contractuelle',
                $site->label.' ('.$this->place($site).') : '.number_format((int) $offer->contract_total_amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA. Répondez avant le ".$offer->application_deadline?->format('d/m/Y à H:i').'.',
                ['type' => 'offer_opportunity', 'id' => $offer->id], $offer, 'opportunity.offer:'.$offer->id);
        }
    }

    /**
     * Relance du Back Office sur une offre contractuelle : les professionnels concernés qui n’ont pas encore répondu
     * sont prévenus de nouveau, une fois par jour. Renvoie le nombre de professionnels relancés.
     *
     * @param  list<string>  $tradeIds
     */
    public function remindContractOffer(Offer $offer, array $tradeIds = []): int
    {
        $offer->loadMissing('site.commune');
        $site = $offer->site;
        $responded = $offer->contractorBids()->where('status', '!=', 'brouillon')->pluck('contractor_id')->all();
        $count = 0;

        foreach ($this->candidates($site, $tradeIds)->reject(fn (Contractor $contractor) => in_array($contractor->getKey(), $responded, true)) as $contractor) {
            $dedup = 'opportunity.offer.reminder:'.$offer->id.':'.now()->toDateString();

            if (Notification::query()->where('dedup_key', $dedup.':'.$contractor->user_id)->exists()) {
                continue;
            }

            $this->notify($contractor, 'opportunity.offer', 'Rappel · offre contractuelle',
                $site->label.' ('.$this->place($site).') attend encore des réponses : '.number_format((int) $offer->contract_total_amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA. Répondez avant le ".$offer->application_deadline?->format('d/m/Y à H:i').'.',
                ['type' => 'offer_opportunity', 'id' => $offer->id], $offer, $dedup);
            $count++;
        }

        return $count;
    }

    /**
     * Entrepreneurs avec un compte, actifs, intervenant dans la ville du chantier et exerçant un des métiers donnés
     * (aucun : tous).
     *
     * @param  list<string>  $tradeIds
     * @return Collection<int, Contractor>
     */
    private function candidates(Site $site, array $tradeIds): Collection
    {
        $city = $site->commune?->city_id;

        if (! $city) {
            return collect();
        }

        return Contractor::query()
            ->with('user')
            ->whereNotNull('user_id')
            ->where('status', ContractorStatus::Actif->value)
            ->where(fn (Builder $query) => $query->where('city_id', $city)->orWhereHas('zones', fn (Builder $query) => $query->whereKey($city)))
            ->when($tradeIds !== [], fn (Builder $query) => $query->whereHas('trades', fn (Builder $query) => $query->whereKey($tradeIds)))
            ->get();
    }

    private function place(Site $site): string
    {
        return collect([$site->neighborhood?->name ?? $site->commune?->name, $site->commune?->city?->name])->filter()->unique()->implode(', ');
    }
}
