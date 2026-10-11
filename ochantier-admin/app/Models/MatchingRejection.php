<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recommandation rejetée (ou archivée) pour une offre ; `candidate` est la clé du profil.
 */
#[Fillable(['need_offer_id', 'candidate', 'reason', 'nature', 'criterion', 'comment', 'corrective_action', 'author', 'rejected_on'])]
class MatchingRejection extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rejected_on' => 'date',
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
