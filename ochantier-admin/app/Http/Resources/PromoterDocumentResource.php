<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use App\Models\PromoterDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pièce du dossier KYC.
 *
 * @mixin PromoterDocument
 */
class PromoterDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $missing = $this->status === DocumentStatus::Missing;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'required' => $this->type->isRequired(),
            'reference' => $this->reference ?? '—',
            'addedAt' => $this->added_at?->translatedFormat('d M Y') ?? '—',
            'expiresAt' => $missing ? '—' : ($this->expires_at?->translatedFormat('d M Y') ?? 'Sans expiration'),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone(), 'icon' => $this->status->icon()],
            'rejectionReason' => $this->rejection_reason,
            'hasFile' => $this->hasFile(),
            'canValidate' => $this->canBeValidated(),
            'canReject' => $this->canBeRejected(),
        ];
    }
}
