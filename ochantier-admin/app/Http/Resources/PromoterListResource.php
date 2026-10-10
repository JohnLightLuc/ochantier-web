<?php

namespace App\Http\Resources;

use App\Models\Promoter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la liste des promoteurs.
 *
 * @mixin Promoter
 */
class PromoterListResource extends JsonResource
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
            'representative' => $this->representative,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'location' => $this->location,
            'sitesCount' => count($this->demo('sites')),
            'payment' => ['label' => $this->payment_status->label(), 'tone' => $this->payment_status->tone()],
            'kyc' => ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'icon' => $kyc->icon()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
        ];
    }
}
