<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\QuoteRequestTrade;
use App\Support\Admin\QuoteRequestDirectory;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des demandes de devis du Back Office : un corps de métier d’une demande.
 *
 * @mixin QuoteRequestTrade
 */
class QuoteRequestListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quoteRequest = $this->quoteRequest;
        $site = $quoteRequest->site;
        $selected = $this->selectedBid;
        $status = (string) $this->display_status;

        return [
            'id' => $this->id,
            'reference' => $quoteRequest->reference,
            'quote_request_id' => $quoteRequest->id,
            'trade' => $this->trade?->only(['code', 'label']),
            'site' => ['id' => $site->id, 'label' => $site->label, 'commune' => $site->commune?->name, 'city' => $site->commune?->city?->name],
            'promoter' => ['name' => app(QuoteRequestDirectory::class)->userName($site->promoterProfile?->user)],
            'status' => ['value' => $status, 'label' => QuoteRequestDirectory::STATUSES[$status] ?? $status],
            'bids_count' => (int) $this->bids_count,
            'selected' => $selected ? [
                'contractor' => $selected->contractor?->only(['id', 'reference', 'name']),
                'amount' => $selected->amount,
                'service_fee_amount' => $selected->service_fee_amount,
                'visit_date' => $this->date($selected->visit_date),
                'visit_slot' => $selected->visit_slot,
            ] : null,
            'quote_total_amount' => $this->quote_total_amount,
            'response_deadline' => $quoteRequest->response_deadline?->toIso8601String(),
            'submitted_at' => $quoteRequest->submitted_at?->toIso8601String(),
        ];
    }
}
