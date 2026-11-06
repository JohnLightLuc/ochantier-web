<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\PaymentTransactionStatusHistory;
use App\Models\Receipt;
use App\Support\Media\MediaStore;
use Illuminate\Http\Request;

/**
 * Fiche d’un encaissement du Back Office : détail du montant, échéances réglées, reçus et historique.
 *
 * @mixin PaymentTransaction
 */
class TransactionDetailResource extends TransactionListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaStore::class);

        return [
            ...parent::toArray($request),
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'phone' => $this->metadata['phone'] ?? null,
            'lines' => $this->metadata['lines'] ?? [],
            'failure' => $this->failure_message ? ['code' => $this->failure_code, 'message' => $this->failure_message] : null,
            'invoice' => $this->subscriptionInvoice ? ['reference' => $this->subscriptionInvoice->reference, 'label' => $this->subscriptionInvoice->label] : null,
            'allocations' => $this->allocations->map(fn (PaymentAllocation $allocation) => [
                'amount' => $allocation->amount,
                'schedule' => ['id' => $allocation->paymentSchedule->id, 'reference' => $allocation->paymentSchedule->reference, 'type' => $this->status($allocation->paymentSchedule->schedule_type)],
            ])->values()->all(),
            'receipts' => $this->receipts->map(fn (Receipt $receipt) => [
                'number' => $receipt->number,
                'issued_at' => $receipt->issued_at?->toIso8601String(),
                'url' => $receipt->mediaFile ? $media->temporaryUrl($receipt->mediaFile) : null,
            ])->values()->all(),
            'history' => $this->statusHistories->sortBy('created_at')->map(fn (PaymentTransactionStatusHistory $history) => [
                'from' => $this->status($history->from_status),
                'to' => $this->status($history->to_status),
                'at' => $history->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
