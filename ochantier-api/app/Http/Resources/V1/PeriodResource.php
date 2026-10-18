<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * Période déclarée dans le calendrier de l’ouvrier (indisponibilité, congé, disponibilité…).
 *
 * @mixin \App\Models\WorkerPeriod
 */
class PeriodResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_on' => $this->date($this->starts_on),
            'ends_on' => $this->date($this->ends_on),
            'type' => $this->status($this->type),
            'reason' => $this->reason,
            'zone' => $this->zone,
            'comment' => $this->comment,
        ];
    }
}
