<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Site;
use App\Support\Media\MediaStore;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * Chantier dans « Mes chantiers » : photo, localisation, calendrier, badge et actions possibles.
 *
 * @mixin Site
 */
class SiteResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sites = app(SiteManager::class);
        $offer = app(OfferDraft::class)->summary($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'internal_reference' => $this->internal_reference,
            'title' => $this->label,
            'work_type' => $this->status($this->work_type),
            'property_type' => $this->constructionType?->only(['id', 'code', 'label']),
            'photo_url' => $this->mainImageMedia ? app(MediaStore::class)->temporaryUrl($this->mainImageMedia) : null,
            'address' => [
                'address' => $this->address,
                'commune' => $this->commune?->only(['id', 'name']),
                'neighborhood' => $this->neighborhood?->only(['id', 'name']),
                'location' => $this->location,
            ],
            'start_date' => $this->date($this->planned_start_date),
            'end_date' => $this->date($this->planned_end_date),
            'duration_days' => $this->planned_duration_days,
            'offer_summary' => $offer ? [
                'type' => $offer['type'],
                'workers_count' => $offer['workers_count'] ?? null,
                'amount' => $offer['amount'] ?? $offer['estimate'] ?? null,
                'payment_mode' => $offer['payment_mode'] ?? null,
            ] : null,
            'pending_proposal' => ($proposal = $this->proposals()->whereIn('status', [ProposalStatus::AValider, ProposalStatus::Acceptee])->latest('published_at')->first()) ? [
                'id' => $proposal->id,
                'reservation_id' => $proposal->offer_id,
                'status' => $this->status($proposal->status),
                'deposit_amount' => $proposal->deposit_amount,
            ] : null,
            'status' => $this->status($this->status),
            'display_status' => $sites->displayStatus($this->resource),
            'schedule' => $sites->schedule($this->resource),
            'quote_request' => ($quote = app(QuoteRequests::class)->current($this->resource)) ? [
                'id' => $quote->id,
                'reference' => $quote->reference,
                'status' => $this->status($quote->status),
                'action' => app(QuoteRequests::class)->action($quote),
            ] : null,
            'contractor_offers' => in_array($this->status, [SiteStatus::Recherche, SiteStatus::Paiement], true) && ($action = app(ContractorOffers::class)->action($this->resource)) ? ['action' => $action] : null,
            'can_edit' => $this->status === SiteStatus::Brouillon,
            'can_delete' => $this->status === SiteStatus::Brouillon && ! app(QuoteRequests::class)->hasOrder($this->resource),
            'missing_for_publication' => $this->status === SiteStatus::Brouillon ? $sites->missingForPublication($this->resource) : [],
            'client_uuid' => $this->client_uuid,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
