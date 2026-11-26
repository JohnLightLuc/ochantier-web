<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Resource;
use App\Models\QuoteBid;
use App\Models\QuoteRequestTrade;
use App\Models\QuoteVersion;
use App\Support\Admin\QuoteRequestDirectory;
use App\Support\Admin\QuoteRequestOperations;
use App\Support\PhoneNumber;
use App\Support\Promoter\QuoteRequests;
use Illuminate\Http\Request;

/**
 * Fiche d’une demande de devis (un corps de métier) dans le Back Office : la demande et son chantier, les
 * propositions de visite, la mission de visite, le paiement de la commande, les versions du devis, la chronologie et
 * les décisions possibles.
 *
 * @mixin QuoteRequestTrade
 */
class QuoteRequestDetailResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directory = app(QuoteRequestDirectory::class);
        $quoteRequest = $this->quoteRequest;
        $site = $quoteRequest->site;
        $promoterUser = $site->promoterProfile?->user;
        $selected = $this->selectedBid;
        $payment = $quoteRequest->paymentTransaction;
        $status = (string) $this->display_status;
        $best = $quoteRequest->status->value === 'recherche' ? app(QuoteRequests::class)->bestBids($this->resource)->modelKeys() : [];

        return [
            ...(new QuoteRequestListResource($this->resource))->toArray($request),
            'request' => [
                'status' => $this->status($quoteRequest->status),
                'notes' => $quoteRequest->notes,
                'trades_count' => $quoteRequest->trades->count(),
                'other_lines' => $directory->siblings($this->resource),
                'ordered_at' => $quoteRequest->ordered_at?->toIso8601String(),
                'received_at' => $quoteRequest->received_at?->toIso8601String(),
                'cancelled_at' => $quoteRequest->cancelled_at?->toIso8601String(),
            ],
            'promoter' => [
                'name' => $directory->userName($promoterUser),
                'phone' => $promoterUser ? PhoneNumber::of($promoterUser)?->toArray()['phone'] : null,
                'email' => $promoterUser?->email,
            ],
            'bids' => $this->bids->map(fn (QuoteBid $bid) => [
                'id' => $bid->id,
                'contractor' => $bid->contractor ? [...$bid->contractor->only(['id', 'reference', 'name']), 'rating' => $bid->contractor->rating_avg !== null ? (float) $bid->contractor->rating_avg : null] : null,
                'fee_amount' => $bid->fee_amount,
                'service_fee_percent' => $bid->service_fee_percent,
                'service_fee_amount' => $bid->service_fee_amount,
                'amount' => $bid->amount,
                'visit_date' => $this->date($bid->visit_date),
                'visit_slot' => $bid->visit_slot,
                'visit_duration' => $bid->visit_duration,
                'distance_km' => $bid->distance_km,
                'comment' => $bid->comment,
                'status' => $this->status($bid->status),
                'is_selected' => $bid->id === $this->selected_bid_id,
                'is_shown' => in_array($bid->id, $best, true),
                'created_at' => $bid->created_at?->toIso8601String(),
            ])->values(),
            'visit' => $selected ? [
                'status' => $this->status($selected->visit_status),
                'date' => $this->date($selected->visit_date),
                'slot' => $selected->visit_slot,
                'duration' => $selected->visit_duration,
                'visited_at' => $selected->visited_at?->toIso8601String(),
                'confirmation' => $selected->visit_status?->value === 'realisee' ? [
                    'status' => $selected->visit_confirmation
                        ? $this->status($selected->visit_confirmation)
                        : ['value' => 'a_confirmer', 'label' => 'À confirmer par le promoteur'],
                    'deadline' => $selected->visit_confirmation ? null : app(QuoteRequests::class)->confirmationDeadline($selected)->toIso8601String(),
                    'confirmed_at' => $selected->visit_confirmed_at?->toIso8601String(),
                    'contest_reason' => $selected->visit_contest_reason,
                    'contested_at' => $selected->visit_contested_at?->toIso8601String(),
                ] : null,
                'reschedule' => $selected->reschedule_requested_at ? [
                    'date' => $this->date($selected->reschedule_date),
                    'slot' => $selected->reschedule_slot,
                    'reason' => $selected->reschedule_reason,
                    'requested_at' => $selected->reschedule_requested_at->toIso8601String(),
                ] : null,
            ] : null,
            'payment' => $payment ? [
                'reference' => $payment->reference,
                'status' => $this->status($payment->status),
                'amount' => (int) $payment->amount,
                'completed_at' => $payment->completed_at?->toIso8601String(),
                'covers_trades' => $quoteRequest->trades->count(),
            ] : null,
            'versions' => $this->versions->map(fn (QuoteVersion $version) => [
                'id' => $version->id,
                'number' => $version->number,
                'version' => $version->version,
                'status' => $this->status($version->status),
                'total_ht' => $version->total_ht,
                'vat_amount' => $version->vat_amount,
                'total_ttc' => $version->total_ttc,
                'works_duration' => $version->works_duration,
                'validity_days' => $version->validity_days,
                'sent_at' => $version->sent_at?->toIso8601String(),
                'received_at' => $version->received_at?->toIso8601String(),
                'document' => $version->document ? DocumentResource::make($version->document)->resolve($request) : null,
            ])->values(),
            'timeline' => $directory->timeline($this->resource),
            'actions' => [
                'extend' => $quoteRequest->status->value === 'recherche',
                'cancel' => $quoteRequest->status->value === 'recherche',
                'remind_promoter' => in_array($status, QuoteRequestOperations::REMINDABLE['promoter'], true) && ($status !== 'ouverte' || $this->bids_count > 0),
                'remind_contractor' => in_array($status, QuoteRequestOperations::REMINDABLE['contractor'], true) && $selected?->contractor !== null,
                'decide_visit' => $selected?->visit_confirmation?->value === 'contestee',
            ],
        ];
    }
}
