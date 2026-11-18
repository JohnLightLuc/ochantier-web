<?php

namespace App\Models;

use App\Enums\QuoteBidStatus;
use App\Enums\QuoteVisitStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proposition d’un entrepreneur pour établir le devis d’un corps de métier : frais de visite ou d’étude, frais de
 * service O’CHANTIER, date, créneau et durée de la visite. Commandée, elle devient la mission de visite, puis porte
 * les versions du devis. Les anciennes offres d’ouvriers simulés gardent worker_id.
 */
class QuoteBid extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_request_trade_id', 'worker_id', 'contractor_id', 'amount', 'fee_amount', 'service_fee_percent', 'service_fee_amount',
        'visit_date', 'visit_slot', 'visit_duration', 'comment', 'distance_km', 'status', 'withdrawn_at', 'visit_status',
        'visited_at', 'reschedule_date', 'reschedule_slot', 'reschedule_reason', 'reschedule_requested_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteBidStatus::class,
            'visit_status' => QuoteVisitStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'service_fee_percent' => 'float',
            'service_fee_amount' => 'integer',
            'visit_date' => 'date',
            'reschedule_date' => 'date',
            'distance_km' => 'float',
            'withdrawn_at' => 'datetime',
            'visited_at' => 'datetime',
            'reschedule_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuoteRequestTrade, $this>
     */
    public function quoteRequestTrade(): BelongsTo
    {
        return $this->belongsTo(QuoteRequestTrade::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return HasMany<QuoteVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class);
    }
}
