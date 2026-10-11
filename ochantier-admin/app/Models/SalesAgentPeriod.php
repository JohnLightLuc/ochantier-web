<?php

namespace App\Models;

use App\Enums\SalesAgentAvailability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Congé, indisponibilité ou mission déclarée pour un commercial.
 */
#[Fillable(['starts_on', 'ends_on', 'type', 'reason', 'zone', 'replacement', 'comment'])]
class SalesAgentPeriod extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'type' => SalesAgentAvailability::class,
        ];
    }

    /**
     * @return BelongsTo<SalesAgent, $this>
     */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }
}
