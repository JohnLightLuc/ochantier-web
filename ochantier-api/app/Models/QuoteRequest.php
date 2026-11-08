<?php

namespace App\Models;

use App\Enums\QuoteRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande de devis d’un chantier (offre contractuelle sans devis) : corps de métier demandés, offres des techniciens,
 * commande payée en une fois, puis devis réalisés.
 */
class QuoteRequest extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'site_id', 'status', 'notes', 'total_amount', 'payment_transaction_id', 'requested_by_user_id',
        'submitted_at', 'ordered_at', 'received_at', 'cancelled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteRequestStatus::class,
            'total_amount' => 'integer',
            'submitted_at' => 'datetime',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<QuoteRequestTrade, $this>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(QuoteRequestTrade::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
