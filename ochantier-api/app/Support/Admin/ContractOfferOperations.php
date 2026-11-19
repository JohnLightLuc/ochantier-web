<?php

namespace App\Support\Admin;

use App\Enums\ContractorBidStatus;
use App\Enums\OfferStatus;
use App\Enums\SiteStatus;
use App\Models\Admin;
use App\Models\ContractorBid;
use App\Models\Offer;
use App\Support\Contractor\ContractorNotifier;
use App\Support\Payments\PaymentHolds;
use App\Support\Promoter\PromoterNotifier;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur une offre contractuelle : prolonger la date limite, annuler l’offre avant l’acompte et
 * relancer le promoteur (choisir une offre, payer l’acompte) ou les professionnels qui n’ont pas répondu. Chaque
 * décision est tracée dans le journal d’audit de l’offre.
 */
class ContractOfferOperations
{
    /**
     * Statuts affichés d’une offre encore ouverte (aucun acompte payé).
     */
    private const OPEN = ['ouverte', 'en_comparaison', 'acompte_attendu', 'expiree'];

    /**
     * Statuts affichés pour lesquels une relance a un sens, par destinataire.
     */
    public const REMINDABLE = [
        'promoter' => ['en_comparaison', 'acompte_attendu'],
        'contractors' => ['ouverte', 'en_comparaison'],
    ];

    public function __construct(private ContractOfferDirectory $directory, private PromoterNotifier $promoters, private ContractorNotifier $contractors, private PaymentHolds $holds) {}

    public function extend(Offer $offer, CarbonInterface $deadline, Admin $admin, Request $request): void
    {
        $this->ensureOpen($offer, 'Seule une offre sans acompte payé peut être prolongée.');
        $before = $offer->application_deadline?->toIso8601String();

        $offer->forceFill(['application_deadline' => $deadline, 'extension_count' => $offer->extension_count + 1, 'lock_version' => $offer->lock_version + 1])->save();
        AdminAudit::record($admin, 'offers', 'contract_offer.extended', $request, $offer, ['deadline' => $before], ['deadline' => $deadline->toIso8601String()]);
    }

    /**
     * Annule l’offre avant l’acompte : les réponses sont annulées, le chantier revient au brouillon (le promoteur
     * peut republier), le promoteur et les professionnels qui ont répondu sont prévenus avec le motif.
     */
    public function cancel(Offer $offer, Admin $admin, string $reason, Request $request): void
    {
        $this->ensureOpen($offer, 'L’acompte est payé : le chantier est attribué, l’offre ne peut plus être annulée.');
        $chosen = $offer->contractorBids()->where('status', ContractorBidStatus::Choisie->value)->first();

        if ($chosen && $this->holds->isHeld('contractor_bid', $chosen->getKey())) {
            throw ValidationException::withMessages(['offer' => 'Le promoteur est en train de payer l’acompte : réessayez dans quelques minutes.']);
        }

        $site = $offer->site;
        $bids = $offer->contractorBids()->with('contractor')->whereIn('status', [ContractorBidStatus::Proposee->value, ContractorBidStatus::Choisie->value, ContractorBidStatus::Brouillon->value])->get();

        DB::transaction(function () use ($offer, $site, $bids, $admin, $reason): void {
            ContractorBid::query()->whereKey($bids->modelKeys())->update(['status' => ContractorBidStatus::Annulee->value]);
            $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => OfferStatus::Annulee->value, 'reason' => $reason, 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $offer->forceFill(['status' => OfferStatus::Annulee, 'cancellation_reason' => $reason, 'lock_version' => $offer->lock_version + 1])->save();
            $site->statusHistories()->create(['from_status' => $site->status->value, 'to_status' => SiteStatus::Brouillon->value, 'reason' => 'Offre contractuelle annulée par O’CHANTIER', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $site->forceFill(['status' => SiteStatus::Brouillon, 'lock_version' => $site->lock_version + 1])->save();
        });

        AdminAudit::record($admin, 'offers', 'contract_offer.cancelled', $request, $offer, ['status' => 'publiee'], ['status' => 'annulee', 'reason' => $reason]);
        $this->promoters->notify($site->promoterProfile, 'offer.cancelled', 'Offre contractuelle annulée · '.$site->label, $reason.' Vous pouvez la modifier et la publier de nouveau.', $site, ['type' => 'site', 'id' => $site->id], $offer);

        foreach ($bids->where('status', '!=', ContractorBidStatus::Brouillon) as $bid) {
            $this->contractors->notify($bid->contractor, 'offer.not_retained', 'Offre contractuelle annulée', $site->label.' : offre annulée par O’CHANTIER. '.$reason, ['type' => 'offer_response', 'id' => $bid->id], $bid);
        }
    }

    /**
     * Rappel au promoteur (choisir une offre ou payer l’acompte) ou aux professionnels concernés qui n’ont pas encore
     * répondu. Un rappel par destinataire et par jour.
     */
    public function remind(Offer $offer, string $target, ?string $message, Admin $admin, Request $request): int
    {
        $status = $this->directory->status($offer);

        if (! in_array($status, self::REMINDABLE[$target], true)) {
            throw ValidationException::withMessages(['target' => $target === 'promoter' ? 'Aucune action n’est attendue du promoteur à ce stade.' : 'Les professionnels ne peuvent plus répondre à cette offre.']);
        }

        $site = $offer->site;

        if ($target === 'promoter') {
            $body = $message ?: ($status === 'acompte_attendu'
                ? 'Vous avez choisi une offre pour « '.$site->label.' » : payez l’acompte pour confirmer le professionnel.'
                : 'Des professionnels ont répondu à votre offre contractuelle « '.$site->label.' » : comparez-les et choisissez la vôtre.');
            $sent = $this->promoters->notify($site->promoterProfile, 'offer.reminder', 'Rappel · '.$site->label, $body, $site, ['type' => 'site', 'id' => $site->id], $offer, 'offer.reminder:'.$offer->id.':'.now()->toDateString());
        } else {
            $origin = $this->directory->quoteOrigin($offer);
            $tradeIds = $origin ? DB::table('quote_request_trades')->whereIn('id', $origin['line_ids'])->pluck('trade_id')->all() : [];
            $sent = $this->contractors->remindContractOffer($offer, $tradeIds);
            $body = 'Rappel de l’offre envoyé à '.$sent.' professionnel'.($sent > 1 ? 's' : '').'.';
        }

        if ($sent === 0) {
            throw ValidationException::withMessages(['target' => $target === 'promoter' ? 'Une relance a déjà été envoyée aujourd’hui au promoteur.' : 'Aucun professionnel à relancer aujourd’hui (déjà relancés ou tous ont répondu).']);
        }

        AdminAudit::record($admin, 'offers', 'contract_offer.reminded', $request, $offer, null, ['target' => $target, 'message' => $body]);

        return $sent;
    }

    private function ensureOpen(Offer $offer, string $refusal): void
    {
        if (! in_array($this->directory->status($offer), self::OPEN, true)) {
            throw ValidationException::withMessages(['offer' => $refusal]);
        }
    }
}
