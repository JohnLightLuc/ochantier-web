<?php

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la liste des ouvriers.
 *
 * @mixin Worker
 */
class WorkerListResource extends JsonResource
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
            'slug' => $this->slug,
            'code' => $this->code,
            'name' => $this->name,
            'trade' => $this->trade,
            'experienceYears' => $this->experience_years,
            'dailyRate' => $this->daily_rate,
            'rating' => $this->rating,
            'availability' => ['value' => $this->availability->value, 'label' => $this->availability->label(), 'tone' => $this->availability->tone()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
        ];
    }
}
