<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * Avis publié sur une prestation (note globale pondérée, critères et réponse éventuelle).
 *
 * @mixin \App\Models\Review
 */
class ReviewResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'date' => $this->date($this->date),
            'author' => $this->author,
            'author_type' => $this->author_type,
            'target' => $this->target,
            'trade' => $this->trade,
            'site' => $this->whenLoaded('site', fn (): string => $this->site->name),
            'period_from' => $this->date($this->period_from),
            'period_to' => $this->date($this->period_to),
            'score' => $this->score(),
            'criteria' => $this->criteria,
            'comment' => $this->comment,
            'positives' => $this->positives,
            'negatives' => $this->negatives,
            'recommends' => $this->recommends,
            'status' => $this->status,
            'reply' => $this->reply,
        ];
    }
}
