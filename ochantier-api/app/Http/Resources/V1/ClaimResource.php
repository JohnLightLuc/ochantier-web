<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * Réclamation déposée depuis une application.
 *
 * @mixin \App\Models\Claim
 */
class ClaimResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'date' => $this->date($this->date),
            'category' => $this->category,
            'subject' => $this->subject,
            'description' => $this->description,
            'operation_reference' => $this->operation_reference,
            'assignment_reference' => $this->assignment_reference,
            'other_party' => $this->other_party['name'] ?? null,
            'site' => $this->whenLoaded('site', fn (): string => $this->site->name),
            'status' => $this->status,
            'open' => $this->isOpen(),
            'due_date' => $this->date($this->due_date),
            'resolution' => $this->resolution,
        ];
    }
}
