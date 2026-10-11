<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sélection de profils pour une offre : ouvriers retenus (`main`), remplaçants (nom → rôle) et statut de validation.
 */
#[Fillable(['need_offer_id', 'main', 'replacements', 'status', 'author', 'updated_on', 'note'])]
class MatchingSelection extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'main' => 'array',
            'replacements' => 'array',
            'updated_on' => 'date',
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
