<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\ClaimCategory;
use App\Http\Resources\V1\Resource;
use App\Models\Claim;
use App\Support\Admin\ClaimDirectory;
use App\Support\ClaimFiling;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des réclamations.
 *
 * @mixin Claim
 */
class ClaimResource extends Resource
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
            'subject' => $this->subject,
            'claimant' => ['type' => $this->claimant_type, 'type_label' => ClaimDirectory::CLAIMANT_TYPES[$this->claimant_type] ?? $this->claimant_type, 'id' => $this->claimant_id, 'name' => $directory->partyName($this->claimant) ?? '—'],
            'site' => $this->site ? ['id' => $this->site->id, 'title' => $this->site->label] : null,
            'priority' => $this->status($this->priority),
            'status' => $this->status($this->status),
            'owner' => $this->ownerAdmin?->fullName(),
            'due_at' => $this->due_at?->toIso8601String(),
            'overdue' => in_array($this->status, ClaimFiling::OPEN, true) && $this->due_at?->isPast() === true,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
