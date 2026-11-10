<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Document;
use App\Models\Need;
use App\Models\Offer;
use App\Models\OfferMilestone;
use App\Models\Site;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\Reservations;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * « Détail chantier » : la carte, plus la description, les accès, le responsable, les réservations et les documents
 * joints. La première offre du chantier est la réservation initiale (« offer.published ») ; les suivantes, ajoutées
 * après la publication, sont des réservations supplémentaires.
 *
 * @mixin Site
 */
class SiteDetailResource extends SiteResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sites = app(SiteManager::class);
        $responsible = $this->responsibleUser;
        $offers = $this->offers()->with(['needs.trade', 'milestones', 'proposals'])->oldest()->oldest('id')->get();
        $initial = $offers->first();

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'access_instructions' => $this->access_instructions,
            'responsible' => $responsible ? $sites->person($responsible, $responsible->is($request->user()) ? 'Moi-même' : null) : null,
            'offer' => [
                'draft' => app(OfferDraft::class)->summary($this->resource),
                'published' => $initial ? $this->published($initial) : null,
            ],
            'reservations' => $offers->map(fn (Offer $offer) => $this->reservation($offer, $offer->is($initial)))->values()->all(),
            'workers_total' => (int) $offers
                ->reject(fn (Offer $offer) => $offer->status === OfferStatus::Annulee)
                ->sum(fn (Offer $offer) => $offer->needs->reject(fn (Need $need) => $need->status === NeedStatus::Annule)->sum('headcount')),
            'contractor_offers' => ($contract = app(ContractorOffers::class)->openOffer($this->resource)) ? app(ContractorOffers::class)->summary($contract) : null,
            'quote_request' => ($quote = app(QuoteRequests::class)->current($this->resource)) ? app(QuoteRequests::class)->summary($quote) : null,
            'documents' => $sites->activeDocuments($this->resource)
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve())
                ->values()
                ->all(),
        ];
    }

    /**
     * Réservation du chantier : l’offre publiée, sa nature (initiale ou supplémentaire), sa période, son état côté
     * promoteur et le nombre d’ouvriers demandés.
     *
     * @return array<string, mixed>
     */
    private function reservation(Offer $offer, bool $initial): array
    {
        return [
            ...$this->published($offer),
            'kind' => $initial
                ? ['value' => 'initiale', 'label' => 'Réservation initiale']
                : ['value' => 'supplementaire', 'label' => 'Réservation supplémentaire'],
            'start_date' => $this->date($offer->start_date),
            'end_date' => $this->date($offer->end_date),
            'state' => app(Reservations::class)->state($offer),
            'workers_count' => (int) $offer->needs->sum('headcount'),
        ];
    }

    /**
     * Offre transmise au Back office : référence, statut, besoins par métier ou échéances du forfait.
     *
     * @return array<string, mixed>
     */
    private function published(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'reference' => $offer->reference,
            'type' => $this->status($offer->offer_type),
            'status' => $this->status($offer->status),
            'submitted_at' => $offer->submitted_at?->toIso8601String(),
            'contract_amount' => $offer->contract_total_amount,
            'payment_mode' => $this->status($offer->payment_mode),
            'needs' => $offer->needs->map(fn (Need $need) => [
                'id' => $need->id,
                'reference' => $need->reference,
                'trade' => $need->trade->only(['id', 'code', 'label']),
                'headcount' => $need->headcount,
                'daily_rate' => $need->indicative_daily_budget,
                'status' => $this->status($need->status),
            ])->values()->all(),
            'milestones' => $offer->milestones->sortBy('sequence')->map(fn (OfferMilestone $milestone) => [
                'label' => $milestone->label,
                'amount' => $milestone->amount,
                'due_date' => $this->date($milestone->due_date),
            ])->values()->all(),
        ];
    }
}
