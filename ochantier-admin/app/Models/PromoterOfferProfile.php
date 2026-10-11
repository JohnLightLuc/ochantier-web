<?php

namespace App\Models;

use App\Enums\PromoterOfferProfileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ouvrier choisi par le promoteur dans son offre, suivi du contact à la validation (ou au remplacement).
 *
 * `worker_id` est nul pour les profils de démonstration qui n’existent pas dans le module Ouvriers.
 */
#[Fillable(['worker_id', 'name', 'trade', 'commune', 'experience', 'rating', 'daily_cost', 'availability', 'status', 'source', 'contacted_on', 'channel', 'reason', 'assignment_reference', 'replaced_by', 'replaces'])]
class PromoterOfferProfile extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PromoterOfferProfileStatus::class,
            'contacted_on' => 'date',
            'rating' => 'float',
        ];
    }

    /**
     * @return BelongsTo<PromoterOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(PromoterOffer::class, 'promoter_offer_id');
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
