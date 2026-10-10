<?php

namespace App\Http\Resources;

use App\Models\PromoterActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement du journal d’activité.
 *
 * @mixin PromoterActivity
 */
class PromoterActivityResource extends JsonResource
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
            'day' => $this->occurred_at->isToday() ? 'Aujourd’hui · '.$this->occurred_at->translatedFormat('j M Y') : $this->occurred_at->translatedFormat('d M Y'),
            'time' => $this->occurred_at->format('H:i'),
            'author' => $this->author,
            'kind' => ['value' => $this->author_kind->value, 'label' => $this->author_kind->label(), 'tone' => $this->author_kind->tone()],
            'action' => $this->action,
            'target' => $this->target,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'link' => $this->link,
        ];
    }
}
