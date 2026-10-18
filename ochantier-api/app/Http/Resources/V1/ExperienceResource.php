<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\WorkerExperience
 */
class ExperienceResource extends Resource
{
    /**
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
            'started_at' => $this->date($this->started_at),
            'ended_at' => $this->date($this->ended_at),
            'description' => $this->description,
            'reference' => $this->reference,
        ];
    }
}
