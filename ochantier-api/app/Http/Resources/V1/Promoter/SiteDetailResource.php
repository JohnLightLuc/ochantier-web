<?php

namespace App\Http\Resources\V1\Promoter;

use App\Http\Resources\V1\DocumentResource;
use App\Models\Document;
use App\Models\Need;
use App\Models\Offer;
use App\Models\OfferMilestone;
use App\Models\Site;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * « Détail chantier » : la carte, plus la description, les accès, le responsable et les documents joints.
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

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'access_instructions' => $this->access_instructions,
            'responsible' => $responsible ? $sites->person($responsible, $responsible->is($request->user()) ? 'Moi-même' : null) : null,
            'offer' => [
                'draft' => app(OfferDraft::class)->summary($this->resource),
                'published' => ($offer = $this->offers()->with(['needs.trade', 'milestones'])->latest()->first()) ? $this->published($offer) : null,
            ],
            'documents' => $sites->activeDocuments($this->resource)
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve())
                ->values()
                ->all(),
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
