<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\PaymentTransactionStatus;
use App\Http\Resources\V1\Resource;
use App\Models\PaymentTransaction;
use App\Support\Media\MediaStore;
use Illuminate\Http\Request;

/**
 * Paiement Mobile Money : statut, montants, détail, reçu et message de fin (« Équipe confirmée… »).
 *
 * @mixin PaymentTransaction
 */
class PaymentTransactionResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $receipt = $this->receipts->first();
        $succeeded = $this->status === PaymentTransactionStatus::Reussie;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'purpose' => $this->status($this->purpose),
            'status' => $this->status($this->status),
            'label' => $this->metadata['label'] ?? null,
            'amount' => $this->amount,
            'fees_amount' => $this->fees_amount,
            'total_amount' => $this->amount + $this->fees_amount,
            'lines' => $this->metadata['lines'] ?? [],
            'operator' => $this->operator?->only(['code', 'name']),
            'phone' => $this->metadata['phone'] ?? null,
            'provider_reference' => $this->provider_reference,
            'failure' => $this->failure_code ? ['code' => $this->failure_code, 'message' => $this->failure_message] : null,
            'message' => match (true) {
                $succeeded => $this->metadata['done_text'] ?? 'Paiement réussi.',
                $this->status === PaymentTransactionStatus::Echouee => $this->failure_message ?? 'Le paiement a échoué.',
                default => 'Validez le paiement sur votre téléphone.',
            },
            'site_id' => $this->metadata['site_id'] ?? null,
            'reservation_proposal_id' => $this->metadata['proposal_id'] ?? null,
            'receipt' => $receipt ? [
                'number' => $receipt->number,
                'url' => $receipt->mediaFile ? app(MediaStore::class)->temporaryUrl($receipt->mediaFile) : null,
            ] : null,
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
