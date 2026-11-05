<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\OfferStatus;
use App\Models\Need;
use App\Models\Offer;
use App\Support\Admin\OfferOperations;
use Illuminate\Http\Request;

/**
 * Fiche d’un besoin : métiers et effectifs, calendrier, décisions possibles.
 *
 * @mixin Offer
 */
class OfferDetailResource extends OfferListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $operations = app(OfferOperations::class);
        $engagement = $operations->engagement($this->resource);
        $open = in_array($this->status, OfferOperations::OPEN, true);
        $hasTeam = $operations->hasTeam($this->resource);

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'deliverables' => $this->deliverables,
            'visibility' => $this->status($this->visibility),
            'extension_count' => $this->extension_count,
            'cancellation_reason' => $this->cancellation_reason,
            'suspension_reason' => $this->status === OfferStatus::Suspendue ? $this->statusHistories()->where('to_status', OfferStatus::Suspendue->value)->latest()->latest('id')->first()?->reason : null,
            'engagement' => $engagement,
            'needs' => $this->needs->map(fn (Need $need) => [
                'id' => $need->id,
                'reference' => $need->reference,
                'trade' => $need->trade?->label,
                'headcount' => $need->headcount,
                'assigned' => $need->assignments->where('status', '!=', AssignmentStatus::Annulee)->count(),
                'level' => $need->required_level,
                'min_experience_years' => $need->min_experience_years,
                'start_date' => $this->date($need->start_date),
                'end_date' => $this->date($need->end_date),
                'duration_days' => $need->duration_days,
                'daily_budget' => $need->indicative_daily_budget,
                'payment_frequency' => $need->payment_frequency,
                'status' => $this->status($need->status),
            ])->values()->all(),
            'can' => [
                'suspend' => $open && $engagement === null,
                'resume' => $this->status === OfferStatus::Suspendue,
                'extend' => $open || $this->status === OfferStatus::Suspendue,
                'cancel' => in_array($this->status, OfferOperations::CANCELLABLE, true) && $engagement === null,
                'close' => $this->status === OfferStatus::Pourvue || ($open && $hasTeam),
            ],
        ];
    }
}
