<?php

namespace App\Models;

use App\Enums\QuoteBidStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offre d’un technicien (ouvrier validé du métier) pour établir le devis : prix, date d’exécution, distance.
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
        'quote_request_trade_id', 'worker_id', 'amount', 'visit_date', 'distance_km', 'status',
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
            'amount' => 'integer',
            'visit_date' => 'date',
            'distance_km' => 'float',
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
}
