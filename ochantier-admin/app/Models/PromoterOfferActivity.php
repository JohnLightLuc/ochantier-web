<?php

namespace App\Models;

use App\Enums\NeedActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du journal d’une offre de promoteur.
 */
#[Fillable(['occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin'])]
class PromoterOfferActivity extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'category' => NeedActivityCategory::class,
        ];
    }

    /**
     * @return BelongsTo<PromoterOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(PromoterOffer::class, 'promoter_offer_id');
    }
}
