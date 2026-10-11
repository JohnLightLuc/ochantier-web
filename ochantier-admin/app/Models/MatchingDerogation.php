<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dérogation accordée à un profil bloqué (hors interdiction absolue).
 */
#[Fillable(['need_offer_id', 'candidate', 'justification', 'author', 'granted_on'])]
class MatchingDerogation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<NeedOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(NeedOffer::class, 'need_offer_id');
    }
}
