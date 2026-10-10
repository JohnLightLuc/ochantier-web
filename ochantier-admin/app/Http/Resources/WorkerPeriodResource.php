<?php

namespace App\Http\Resources;

use App\Models\WorkerPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Période déclarée dans le calendrier d’un ouvrier.
 *
 * @mixin WorkerPeriod
 */
class WorkerPeriodResource extends JsonResource
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
            'startsOn' => $this->starts_on->format('Y-m-d'),
            'endsOn' => $this->ends_on->format('Y-m-d'),
            'type' => ['value' => $this->type->value, 'label' => $this->type->label(), 'tone' => $this->type->tone(), 'blocks' => $this->type->blocksAssignment()],
            'reason' => $this->reason,
            'zone' => $this->zone,
            'comment' => $this->comment,
        ];
    }
}
