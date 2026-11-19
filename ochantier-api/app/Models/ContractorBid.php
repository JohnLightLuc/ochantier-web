<?php

namespace App\Models;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorResponseMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offre d’un entrepreneur sur une offre contractuelle : montant (hors commission), durée, taille d’équipe.
 */
class ContractorBid extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'offer_id', 'contractor_id', 'amount', 'duration_days', 'team_size', 'distance_km', 'message', 'status',
        'chosen_at', 'chosen_by_user_id', 'response_mode', 'start_date', 'end_date', 'sent_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractorBidStatus::class,
            'amount' => 'integer',
            'duration_days' => 'integer',
            'team_size' => 'integer',
            'distance_km' => 'float',
            'chosen_at' => 'datetime',
            'response_mode' => ContractorResponseMode::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function chosenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
