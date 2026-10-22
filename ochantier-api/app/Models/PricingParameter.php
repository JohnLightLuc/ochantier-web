<?php

namespace App\Models;

use App\Enums\ParameterStatus;
use App\Enums\PricingParameterScope;
use App\Enums\PricingVariable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variables X (acompte), Y (commission promoteur), Z (commission ouvrier), R (seuil calendaire) : une ligne par valeur, jamais modifiée.
 */
class PricingParameter extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code', 'scope', 'scope_value', 'scope_id', 'value', 'effective_from', 'effective_to', 'reason', 'status',
        'created_by_admin_id', 'approved_by_admin_id', 'approved_at',
    ];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => PricingVariable::class,
            'scope' => PricingParameterScope::class,
            'value' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'status' => ParameterStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
