<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des encaissements du Back Office.
 *
 * @mixin PaymentTransaction
 */
class TransactionListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status($this->status),
            'purpose' => $this->status($this->purpose),
            'label' => $this->metadata['label'] ?? null,
            'site' => $this->site_key ? ['id' => $this->site_key, 'reference' => $this->site_reference, 'label' => $this->site_label] : null,
            'promoter' => ['name' => trim((string) $this->promoter_name)],
            'operator' => $this->operator?->name,
            'amount' => $this->amount,
            'fees_amount' => $this->fees_amount,
            'total_amount' => $this->amount + $this->fees_amount,
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
