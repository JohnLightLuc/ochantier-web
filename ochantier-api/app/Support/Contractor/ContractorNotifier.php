<?php

namespace App\Support\Contractor;

use App\Enums\ContractorStatus;
use App\Enums\Priority;
use App\Models\Contractor;
use App\Models\Mission;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\Site;
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
        'offer.chosen' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir mon offre'],
        'offer.attributed' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Ouvrir le chantier'],
        'offer.not_retained' => ['tab' => 'offres', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir mon offre'],
        'team.accepted' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir l’équipe'],
        'team.refused' => ['tab' => 'chantiers', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir l’équipe'],
        'payment.received' => ['tab' => 'paiements', 'alert' => 'paiements', 'priority' => Priority::Normale, 'sms' => true, 'cta' => 'Voir les paiements'],
    ];

    public function __construct(private NotificationInbox $inbox) {}

    public function notify(Contractor $contractor, string $event, string $title, string $body, array $target = [], mixed $related = null, ?string $dedup = null): void
    {
        $user = $contractor->user;

        if ($user) {
            $this->inbox->send($user, $event, self::EVENTS[$event], $title, $body,
                ['target' => $target ?: ['type' => 'profile', 'id' => $contractor->id], 'cta' => self::EVENTS[$event]['cta']], $related ?? $contractor, $dedup);
        }
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
