<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Contractor;
use App\Support\Contractor\ContractorAccount;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des entrepreneurs du Back Office.
 *
 * @mixin Contractor
 */
class ContractorListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name ?: null,
            'kind' => $this->status($this->kind),
            'phone' => PhoneNumber::of($this->resource)?->toArray()['phone'],
            'email' => $this->email,
            'city' => $this->city?->only(['id', 'name']),
            'trades' => $this->trades->map->only(['code', 'label'])->values(),
            'status' => $this->status($this->status),
            'verification_status' => $this->status($this->verification_status),
            'verification_state' => app(ContractorAccount::class)->state($this->resource),
            'has_account' => $this->user_id !== null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
