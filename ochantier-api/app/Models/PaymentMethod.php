<?php

namespace App\Models;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Moyens de paiement (ouvriers, promoteurs, organisations, commerciaux).
 */
class PaymentMethod extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'owner_type', 'owner_id', 'method_type', 'operator_id', 'account_holder_name', 'account_number_encrypted',
        'account_number_masked', 'is_default', 'verified_at', 'verification_method', 'status', 'created_by_type',
        'created_by_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['account_number_encrypted'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method_type' => PaymentMethodType::class,
            'account_number_encrypted' => 'encrypted',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
            'status' => PaymentMethodStatus::class,
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return BelongsTo<PaymentOperator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(PaymentOperator::class);
    }

    /**
     * @return HasMany<WorkerPayout, $this>
     */
    public function workerPayouts(): HasMany
    {
        return $this->hasMany(WorkerPayout::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * @return HasMany<CommercialPayout, $this>
     */
    public function commercialPayouts(): HasMany
    {
        return $this->hasMany(CommercialPayout::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }
}
