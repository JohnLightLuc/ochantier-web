<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Support\Admin\ClaimDirectory;
use App\Support\Admin\ClaimOperations;
use Illuminate\Http\Request;

/**
 * Fiche d’une réclamation : demande, autre partie, réponse, journal et actions possibles.
 *
 * @mixin Claim
 */
class ClaimDetailResource extends ClaimResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $other = $this->otherParty;

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'other_party' => $other ? ['type' => $this->other_party_type, 'type_label' => ClaimDirectory::CLAIMANT_TYPES[$this->other_party_type] ?? $this->other_party_type, 'id' => $this->other_party_id, 'name' => app(ClaimDirectory::class)->partyName($other)] : null,
            'resolution' => $this->resolution,
            'history' => AuditLog::query()
                ->where('subject_type', $this->resource->getMorphClass())
                ->where('subject_id', $this->id)
                ->latest()
                ->with('actor')
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'reason' => $log->after_values['reason'] ?? null,
                    'by' => $log->actor instanceof Admin ? $log->actor->fullName() : null,
                    'date' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
            'can' => app(ClaimOperations::class)->actions($this->resource),
        ];
    }
}
