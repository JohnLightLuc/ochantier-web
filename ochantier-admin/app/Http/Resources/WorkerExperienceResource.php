<?php

namespace App\Http\Resources;

use App\Models\WorkerExperience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expérience professionnelle d’un ouvrier.
 *
 * @mixin WorkerExperience
 */
class WorkerExperienceResource extends JsonResource
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
            'company' => $this->company,
            'mission' => $this->mission,
            'trade' => $this->trade,
            'site' => $this->site,
            'location' => $this->location,
            'startedAt' => $this->started_at->format('Y-m-d'),
            'endedAt' => $this->ended_at?->format('Y-m-d'),
            'description' => $this->description,
            'reference' => $this->reference,
        ];
    }
}
