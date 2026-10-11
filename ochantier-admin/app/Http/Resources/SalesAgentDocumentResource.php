<?php

namespace App\Http\Resources;

use App\Models\SalesAgentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pièce du dossier d’un commercial.
 *
 * @mixin SalesAgentDocument
 */
class SalesAgentDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'kind' => $this->type->kind(),
            'reference' => $this->reference,
            'fileName' => $this->file_name,
            'addedAt' => $this->added_at?->format('Y-m-d'),
            'expiresAt' => $this->expires_at?->format('Y-m-d'),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
            'rejectionReason' => $this->rejection_reason,
            'internalComment' => $this->internal_comment,
            'hasFile' => $this->hasFile(),
        ];
    }
}
