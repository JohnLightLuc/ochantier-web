<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\NeedStatus;
use App\Enums\OfferType;
use App\Enums\ProposalStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Support\Media\MediaStore;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\Reservations;
use Illuminate\Http\Request;

/**
 * Réservation dans « Réservations » : chantier, période, montants, état et alerte d’action.
 *
 * @mixin Offer
 */
class ReservationResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservations = app(Reservations::class);
        $state = $reservations->state($this->resource);
        $proposal = $reservations->latestProposal($this->resource);
        $current = in_array($state['value'], ['a_valider', 'acompte', 'confirmee'], true) ? $proposal : null;
        [$daily, $total] = $current ? [$current->daily_amount, $current->total_amount] : $this->estimate();
        $deposit = $current ? $current->deposit_amount : ($total ? app(PricingRules::class)->deposit($total) : null);
        $site = $this->site;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title(),
            'type' => $this->status($this->offer_type),
            'site' => [
                'id' => $site->id,
                'reference' => $site->reference,
                'title' => $site->label,
                'address' => $site->address,
                'commune' => $site->commune?->only(['id', 'name']),
                'photo_url' => $site->mainImageMedia ? app(MediaStore::class)->temporaryUrl($site->mainImageMedia) : null,
            ],
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'days' => $this->days(),
            'state' => $state,
            'workers_count' => $current ? $current->proposed_count : $this->requestedCount(),
            'daily_amount' => $daily,
            'total_amount' => $total,
            'total_is_estimate' => $current === null,
            'deposit_amount' => $deposit,
            'alert' => match ($state['value']) {
                'a_valider' => ['title' => 'Offre reçue · acompte '.$this->money($deposit), 'text' => 'La réservation est confirmée après paiement de l’acompte.'],
                'acompte' => ['title' => 'Acompte à payer : '.$this->money($deposit), 'text' => 'Chantier validé après paiement de l’acompte.'],
                default => null,
            },
            'pending_proposal_id' => in_array($state['value'], ['a_valider', 'acompte'], true) ? $proposal?->id : null,
            'last_refusal' => ($refused = $this->proposals->filter(fn (Proposal $item) => $item->status === ProposalStatus::Refusee)->sortByDesc('decided_at')->first()) ? [
                'proposal_id' => $refused->id,
                'reason' => $refused->refusalReason?->only(['code', 'label']),
                'decided_at' => $refused->decided_at?->toIso8601String(),
            ] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * « Offre contractuelle », « Yao Koffi · Maçon » (un ouvrier choisi) ou « Recrutement · 5 ouvriers ».
     */
    protected function title(): string
    {
        if ($this->offer_type === OfferType::Contractuelle) {
            return 'Offre contractuelle';
        }

        $needs = $this->needs;
        $single = $needs->count() === 1 && $needs->first()->headcount === 1 ? $needs->first()->requestedWorkers->first() : null;

        if ($single) {
            return trim($single->worker->first_name.' '.$single->worker->last_name).' · '.$needs->first()->trade->label;
        }

        $count = $this->requestedCount();

        return 'Recrutement · '.$count.' ouvrier'.($count > 1 ? 's' : '');
    }

    protected function days(): ?int
    {
        return $this->start_date && $this->end_date ? (int) $this->start_date->diffInDays($this->end_date) + 1 : null;
    }

    /**
     * Ouvriers demandés, y compris ceux encore recherchés après une proposition partielle.
     */
    protected function requestedCount(): int
    {
        return (int) $this->needs->sum('headcount');
    }

    /**
     * Coût estimé tant qu’aucune équipe n’est proposée : tarifs proposés des besoins sur la durée.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function estimate(): array
    {
        if ($this->offer_type === OfferType::Contractuelle) {
            return [$this->days() ? (int) round($this->contract_total_amount / $this->days()) : null, $this->contract_total_amount];
        }

        $daily = (int) $this->needs
            ->reject(fn (Need $need) => in_array($need->status, [NeedStatus::Annule], true))
            ->sum(fn (Need $need) => $need->headcount * (int) $need->indicative_daily_budget);

        return $daily ? [$daily, $daily * (int) $this->days()] : [null, null];
    }

    protected function money(?int $amount): string
    {
        return number_format((int) $amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }
}
