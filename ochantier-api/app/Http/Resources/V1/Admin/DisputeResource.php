<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\ClaimCategory;
use App\Enums\DisputePartyRole;
use App\Http\Resources\V1\Resource;
use App\Models\Dispute;
use App\Models\DisputeParty;
use App\Support\Admin\ClaimDirectory;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des litiges.
 *
 * @mixin Dispute
 */
class DisputeResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directory = app(ClaimDirectory::class);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'category' => ['value' => $this->category, 'label' => ClaimCategory::tryFrom($this->category)?->label() ?? $this->category],
            'parties' => $this->parties->map(fn (DisputeParty $party) => [
                'type' => $party->party_type,
                'type_label' => ClaimDirectory::CLAIMANT_TYPES[$party->party_type] ?? $party->party_type,
                'id' => $party->party_id,
                'name' => $directory->partyName($party->party) ?? '—',
                'role' => $this->status($party->role),
            ])->sortBy(fn (array $party) => $party['role']['value'] === DisputePartyRole::Demandeur->value ? 0 : 1)->values()->all(),
            'site' => $this->site ? ['id' => $this->site->id, 'title' => $this->site->label] : null,
            'mission' => $this->mission ? ['id' => $this->mission->id, 'reference' => $this->mission->reference] : null,
            'urgency' => $this->status($this->urgency),
            'status' => $this->status($this->status),
            'owner' => $this->assignedAdmin?->fullName(),
            'appeal_of' => $this->appealOfDispute ? ['id' => $this->appealOfDispute->id, 'reference' => $this->appealOfDispute->reference] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
