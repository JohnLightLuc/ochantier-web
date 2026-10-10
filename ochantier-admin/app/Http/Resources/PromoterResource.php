<?php

namespace App\Http\Resources;

use App\Models\Promoter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * En-tête et informations d’un promoteur (fiche détaillée).
 *
 * @mixin Promoter
 */
class PromoterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kyc = $this->kycStatus();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'representative' => $this->representative,
            'representativeRole' => $this->representative_role,
            'legalForm' => $this->legal_form,
            'rccm' => $this->rccm,
            'ncc' => $this->ncc,
            'sector' => $this->sector,
            'headOffice' => $this->head_office,
            'phone' => $this->phone,
            'email' => $this->email,
            'accountType' => $this->account_type->label(),
            'location' => $this->location,
            'registeredAt' => $this->registered_at->translatedFormat('d M Y'),
            'lastActiveAt' => $this->last_active_at?->translatedFormat('j M Y · H:i'),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
            'kyc' => ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'icon' => $kyc->icon()],
            'suspension' => $this->suspended_at ? [
                'date' => $this->suspended_at->translatedFormat('j M Y'),
                'reason' => $this->suspension_reason,
            ] : null,
        ];
    }
}
