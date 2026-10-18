<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Promoter
 */
class PromoterResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'account_type' => $this->status($this->account_type),
            'legal_form' => $this->legal_form,
            'rccm' => $this->rccm,
            'ncc' => $this->ncc,
            'sector' => $this->sector,
            'head_office' => $this->head_office,
            'location' => $this->location,
            'representative' => $this->representative,
            'representative_role' => $this->representative_role,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status($this->status),
            'payment_status' => $this->status($this->payment_status),
            'suspension_reason' => $this->suspension_reason,
            'registered_at' => $this->date($this->registered_at),
            'kyc_status' => $this->whenLoaded('documents', fn () => $this->status($this->kycStatus())),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
