<?php

namespace App\Support\Matching;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\Suggestion;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Publie l’offre et présente au promoteur une proposition d’équipe, à partir des suggestions retenues.
 */
class ProposalPublisher
{
    public function __construct(private PricingRules $pricing, private PromoterNotifier $notifier) {}

    /**
     * @param  Collection<int, Suggestion>  $suggestions  Ouvriers proposés, déjà tarifés.
     */
    public function publish(Offer $offer, Collection $suggestions, ?Admin $admin, string $reason): Proposal
    {
        $actor = $admin ? ['actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()] : ['actor_type' => 'systeme', 'actor_id' => null];

        $proposal = DB::transaction(function () use ($offer, $suggestions, $admin, $reason, $actor): Proposal {
            if ($offer->status !== OfferStatus::Publiee) {
                $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => OfferStatus::Publiee->value, 'reason' => $reason, ...$actor]);
                $offer->forceFill(['status' => OfferStatus::Publiee, 'published_at' => now()])->save();
            }

            $needs = $offer->needs()->get();
            $requested = (int) $needs->whereNotIn('status', [NeedStatus::Annule, NeedStatus::Confirme, NeedStatus::EnCours, NeedStatus::Termine])->sum('headcount');
            $days = (int) $offer->start_date->diffInDays($offer->end_date ?? $offer->start_date) + 1;
            $prices = $suggestions->groupBy('need_id')->map(fn (Collection $items) => $this->pricing->breakdown((int) $items->sum('promoter_daily_rate') * $days));

            $proposal = $offer->proposals()->create([
                'reference' => sprintf('PR-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('proposal_reference_seq')")),
                'site_id' => $offer->site_id,
                'sequence' => ($offer->proposals()->max('sequence') ?? 0) + 1,
                'status' => ProposalStatus::AValider,
                'requested_count' => $requested,
                'proposed_count' => $suggestions->count(),
                'is_partial' => $suggestions->count() < $requested,
                'daily_amount' => (int) $suggestions->sum('promoter_daily_rate'),
                'days' => $days,
                'workers_amount' => $prices->sum('workers'),
                'commission_amount' => $prices->sum('commission'),
                'total_amount' => $prices->sum('total'),
                'deposit_amount' => $prices->sum('deposit'),
                'published_by_admin_id' => $admin?->getKey(),
                'expires_at' => now()->addHours((int) (Setting::query()->whereKey('proposals.validity_hours')->value('value') ?? 48)),
            ]);

            Suggestion::query()->whereKey($suggestions->pluck('id')->all())->update(['status' => SuggestionStatus::ProposePromoteur, 'published_to_promoter_at' => now(), 'published_by_admin_id' => $admin?->getKey(), 'proposal_id' => $proposal->getKey()]);

            foreach ($needs->whereIn('id', $suggestions->pluck('need_id')->unique()) as $need) {
                /** @var Need $need */
                $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::PropositionPromoteur->value, 'reason' => $reason, ...$actor]);
                $need->forceFill(['status' => NeedStatus::PropositionPromoteur])->save();
            }

            $site = $offer->site;

            if ($site->status !== SiteStatus::Paiement && in_array($site->status, [SiteStatus::Soumis, SiteStatus::Recherche], true)) {
                $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::Paiement, 'reason' => $reason, ...$actor]);
                $site->forceFill(['status' => SiteStatus::Paiement])->save();
            }

            return $proposal;
        });

        $this->notifier->proposalReceived($proposal);

        return $proposal;
    }

    /**
     * Clôture les propositions sans réponse dans le délai : la recherche reprend sur leurs besoins.
     */
    public function expireOverdue(Offer $offer): void
    {
        $overdue = $offer->proposals()->where('status', ProposalStatus::AValider)->whereNotNull('expires_at')->where('expires_at', '<', now())->get();

        foreach ($overdue as $proposal) {
            /** @var Proposal $proposal */
            DB::transaction(function () use ($offer, $proposal): void {
                $proposal->suggestions()->update(['status' => SuggestionStatus::Expire]);
                $proposal->forceFill(['status' => ProposalStatus::Expiree])->save();

                foreach ($offer->needs()->where('status', NeedStatus::PropositionPromoteur)->get() as $need) {
                    /** @var Need $need */
                    $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::Matching->value, 'reason' => 'Proposition expirée', 'actor_type' => 'systeme', 'actor_id' => null]);
                    $need->forceFill(['status' => NeedStatus::Matching])->save();
                }

                $site = $offer->site;

                if ($site->status === SiteStatus::Paiement) {
                    $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::Recherche, 'reason' => 'Proposition expirée', 'actor_type' => 'systeme', 'actor_id' => null]);
                    $site->forceFill(['status' => SiteStatus::Recherche])->save();
                }
            });
        }
    }
}
