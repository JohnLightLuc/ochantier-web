<?php

namespace App\Models;

use App\Enums\QuoteVersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version du devis rédigé par l’entrepreneur après la visite : lignes, TVA, durée, conditions, validité et
 * signature. Envoyée, elle ne se modifie plus ; une correction crée la version suivante.
 */
class QuoteVersion extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_request_trade_id', 'quote_bid_id', 'number', 'version', 'status', 'items', 'vat_applied', 'vat_rate', 'total_ht',
        'vat_amount', 'total_ttc', 'works_duration', 'conditions', 'validity_days', 'observations', 'signed_at', 'sent_at',
        'received_at', 'document_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteVersionStatus::class,
            'items' => 'array',
            'vat_applied' => 'boolean',
            'vat_rate' => 'float',
            'version' => 'integer',
            'total_ht' => 'integer',
            'vat_amount' => 'integer',
            'total_ttc' => 'integer',
            'validity_days' => 'integer',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
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
     * @return BelongsTo<QuoteBid, $this>
     */
    public function quoteBid(): BelongsTo
    {
        return $this->belongsTo(QuoteBid::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
