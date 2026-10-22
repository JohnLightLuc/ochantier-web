<?php

namespace App\Models;

use App\Enums\CommercialObjectiveIndicator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Objectif chiffré d’un commercial sur une période.
 */
class CommercialObjective extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'commercial_id', 'period_start', 'period_end', 'indicator', 'target_value', 'achieved_value', 'comment',
        'set_by_admin_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'indicator' => CommercialObjectiveIndicator::class,
        ];
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function setByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
