<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Support\Promoter\PromoterNotifier;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur un besoin (une offre). Une suspension retire la proposition en attente et la recherche
 * reprend à la réactivation. Dès qu’un acompte est attendu ou payé, l’offre ne peut plus être suspendue ni annulée.
 */
class OfferOperations
{
    public const OPEN = [OfferStatus::Soumise, OfferStatus::EnControle, OfferStatus::Publiee];

    public const CANCELLABLE = [OfferStatus::Soumise, OfferStatus::EnControle, OfferStatus::ACorriger, OfferStatus::Publiee, OfferStatus::Suspendue];

    public function __construct(private PromoterNotifier $notifier) {}

    /**
     * Pourquoi l’offre ne peut plus être arrêtée : acompte attendu, ou ouvriers déjà confirmés.
     */
    public function engagement(Offer $offer): ?string
    {
        return match (true) {
            $offer->proposals()->where('status', ProposalStatus::Acceptee)->exists() => 'Le promoteur a accepté une proposition : un acompte est attendu ou payé.',
            $this->hasTeam($offer) => 'Des ouvriers sont déjà affectés à ce besoin.',
            default => null,
        };
    }

    public function hasTeam(Offer $offer): bool
    {
        return $offer->needs()->whereHas('assignments', fn ($query) => $query->where('status', '!=', AssignmentStatus::Annulee))->exists();
    }

    public function suspend(Offer $offer, Admin $admin, string $reason, Request $request): void
    {
        $this->ensure(in_array($offer->status, self::OPEN, true), 'Seul un besoin ouvert peut être suspendu.');
        $this->ensureNotEngaged($offer);
        $before = $offer->status;

        DB::transaction(function () use ($offer, $admin, $reason): void {
            $this->withdrawProposals($offer, $admin, $reason);
            $this->changeStatus($offer, OfferStatus::Suspendue, $reason, $admin);
        });

        AdminAudit::record($admin, 'offers', 'offer.suspended', $request, $offer, ['status' => $before->value], ['status' => 'suspendue', 'reason' => $reason]);
        $this->notify($offer, 'offer.suspended', 'Réservation suspendue · '.$offer->title, $reason);
    }

    public function resume(Offer $offer, Admin $admin, Request $request): void
    {
        $this->ensure($offer->status === OfferStatus::Suspendue, 'Ce besoin n’est pas suspendu.');
        $target = $offer->statusHistories()->where('to_status', OfferStatus::Suspendue->value)->latest()->latest('id')->first()?->from_status ?? OfferStatus::Soumise;

        $this->changeStatus($offer, $target, 'Reprise de la recherche', $admin);
        AdminAudit::record($admin, 'offers', 'offer.resumed', $request, $offer, ['status' => 'suspendue'], ['status' => $target->value]);
        $this->notify($offer, 'offer.resumed', 'Réservation reprise · '.$offer->title, 'La recherche d’ouvriers reprend.');
    }

    public function extend(Offer $offer, CarbonInterface $deadline, Admin $admin, Request $request): void
    {
        $this->ensure(in_array($offer->status, [...self::OPEN, OfferStatus::Suspendue], true), 'Ce besoin n’est plus ouvert.');
        $before = $offer->application_deadline?->toIso8601String();

        $offer->forceFill(['application_deadline' => $deadline, 'extension_count' => $offer->extension_count + 1, 'lock_version' => $offer->lock_version + 1])->save();
        AdminAudit::record($admin, 'offers', 'offer.extended', $request, $offer, ['deadline' => $before], ['deadline' => $deadline->toIso8601String()]);
    }

    public function cancel(Offer $offer, Admin $admin, string $reason, Request $request): void
    {
        $this->ensure(in_array($offer->status, self::CANCELLABLE, true), 'Ce besoin ne peut plus être annulé.');
        $this->ensureNotEngaged($offer);
        $before = $offer->status;

        DB::transaction(function () use ($offer, $admin, $reason): void {
            $this->withdrawProposals($offer, $admin, $reason);
            $this->closeNeeds($offer, $admin, $reason);
            $this->changeStatus($offer, OfferStatus::Annulee, $reason, $admin, ['cancellation_reason' => $reason]);
        });

        AdminAudit::record($admin, 'offers', 'offer.cancelled', $request, $offer, ['status' => $before->value], ['status' => 'annulee', 'reason' => $reason]);
        $this->notify($offer, 'offer.cancelled', 'Réservation annulée · '.$offer->title, $reason);
    }

    /**
     * Arrête la recherche d’un besoin pourvu, ou partiellement pourvu : les postes restants sont annulés.
     */
    public function close(Offer $offer, Admin $admin, string $reason, Request $request): void
    {
        $partial = in_array($offer->status, self::OPEN, true) && $this->hasTeam($offer);
        $this->ensure($offer->status === OfferStatus::Pourvue || $partial, 'Seul un besoin pourvu, même partiellement, peut être clôturé.');
        $before = $offer->status;

        DB::transaction(function () use ($offer, $admin, $reason): void {
            $this->withdrawProposals($offer, $admin, $reason);
            $this->closeNeeds($offer, $admin, $reason);
            $this->changeStatus($offer, OfferStatus::Cloturee, $reason, $admin);
        });

        AdminAudit::record($admin, 'offers', 'offer.closed', $request, $offer, ['status' => $before->value], ['status' => 'cloturee', 'reason' => $reason]);
    }

    /**
     * Annule la proposition en attente : ses ouvriers redeviennent libres et les besoins retournent au matching.
     */
    public function withdrawProposals(Offer $offer, Admin $admin, string $reason): void
    {
        foreach ($offer->proposals()->where('status', ProposalStatus::AValider)->get() as $proposal) {
            /** @var Proposal $proposal */
            $proposal->suggestions()->update(['status' => SuggestionStatus::Expire]);
            $proposal->update(['status' => ProposalStatus::Annulee]);

            foreach ($offer->needs()->where('status', NeedStatus::PropositionPromoteur)->get() as $need) {
                /** @var Need $need */
                $this->moveNeed($need, NeedStatus::Matching, $reason, $admin);
            }
        }
    }

    /**
     * Annule les besoins encore recherchés ; ceux qui ont une équipe suivent le chantier.
     */
    public function closeNeeds(Offer $offer, Admin $admin, string $reason): void
    {
        foreach ($offer->needs()->whereNotIn('status', [NeedStatus::Confirme, NeedStatus::EnCours, NeedStatus::Termine, NeedStatus::Annule])->get() as $need) {
            /** @var Need $need */
            $this->moveNeed($need, NeedStatus::Annule, $reason, $admin);
        }
    }

    private function moveNeed(Need $need, NeedStatus $status, string $reason, Admin $admin): void
    {
        $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => $status->value, 'reason' => $reason, 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
        $need->forceFill(['status' => $status])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function changeStatus(Offer $offer, OfferStatus $status, string $reason, Admin $admin, array $attributes = []): void
    {
        DB::transaction(function () use ($offer, $status, $reason, $admin, $attributes): void {
            $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => $status->value, 'reason' => $reason, 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $offer->forceFill(['status' => $status, 'lock_version' => $offer->lock_version + 1, ...$attributes])->save();
        });
    }

    private function notify(Offer $offer, string $event, string $title, string $body): void
    {
        $site = $offer->site;
        $this->notifier->notify($site->promoterProfile, $event, $title, $body, $site, ['type' => 'site', 'id' => $site->id], $offer, $event.':'.$offer->id.':'.now()->timestamp);
    }

    private function ensureNotEngaged(Offer $offer): void
    {
        if ($engagement = $this->engagement($offer)) {
            throw ValidationException::withMessages(['offer' => $engagement]);
        }
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['offer' => $message]);
        }
    }
}
