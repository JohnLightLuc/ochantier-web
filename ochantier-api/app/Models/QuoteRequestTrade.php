<?php

namespace App\Models;

use App\Enums\QuoteRequestTradeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corps de métier d’une demande de devis : offres reçues, offre choisie et devis réalisé par le technicien.
 */
class QuoteRequestTrade extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_request_id', 'trade_id', 'status', 'selected_bid_id', 'quote_number', 'quote_items', 'quote_total_amount',
        'visited_on', 'issued_on', 'valid_until', 'quote_document_id', 'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteRequestTradeStatus::class,
            'quote_items' => 'array',
            'quote_total_amount' => 'integer',
            'visited_on' => 'date',
            'issued_on' => 'date',
            'valid_until' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuoteRequest, $this>
     */
    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return HasMany<QuoteBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(QuoteBid::class);
    }

    /**
     * @return BelongsTo<QuoteBid, $this>
     */
    public function selectedBid(): BelongsTo
    {
        return $this->belongsTo(QuoteBid::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function quoteDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
