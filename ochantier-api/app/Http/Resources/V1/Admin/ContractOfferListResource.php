<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Offer;
use App\Support\Admin\ContractOfferDirectory;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des offres contractuelles du Back Office.
 *
 * @mixin Offer
 */
class ContractOfferListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directory = app(ContractOfferDirectory::class);
        $site = $this->site;
        $retained = $this->contractorBid;
        $status = (string) $this->display_status;
        $origin = $directory->quoteOrigin($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'site' => ['id' => $site->id, 'label' => $site->label, 'commune' => $site->commune?->name, 'city' => $site->commune?->city?->name],
            'promoter' => ['name' => $directory->userName($site->promoterProfile?->user)],
            'price' => (int) $this->contract_total_amount,
            'payment_mode' => $this->status($this->payment_mode),
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'status' => ['value' => $status, 'label' => ContractOfferDirectory::STATUSES[$status] ?? $status],
            'responses_count' => (int) $this->responses_count,
            'counter_proposals_count' => (int) $this->counter_proposals_count,
            'retained' => $retained ? ['contractor' => $retained->contractor?->only(['id', 'reference', 'name']), 'total' => $directory->prices($retained)['total']] : null,
            'quote_request' => $origin ? ['id' => $origin['id'], 'reference' => $origin['reference']] : null,
            'application_deadline' => $this->application_deadline?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
