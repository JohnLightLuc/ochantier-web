<?php

namespace App\Http\Resources;

use App\Models\WorkerActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement du journal d’un ouvrier.
 *
 * @mixin WorkerActivity
 */
class WorkerActivityResource extends JsonResource
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
            'date' => $this->occurred_at->format('Y-m-d'),
            'time' => $this->occurred_at->format('H:i'),
            'author' => $this->author,
            'category' => ['value' => $this->category->value, 'label' => $this->category->label(), 'tone' => $this->category->tone(), 'icon' => $this->category->icon()],
            'action' => $this->action,
            'oldValue' => $this->old_value,
            'newValue' => $this->new_value,
            'comment' => $this->comment,
            'origin' => $this->origin,
        ];
    }
}
