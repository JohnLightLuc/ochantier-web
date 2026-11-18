<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Besoin d’une recherche d’effectifs : métier, effectif demandé et tarif journalier proposé.
 */
class StaffingRequestLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'staffing_request_id', 'trade_id', 'headcount', 'daily_rate',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'daily_rate' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StaffingRequest, $this>
     */
    public function staffingRequest(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class);
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return HasMany<StaffingRequestWorker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(StaffingRequestWorker::class);
    }
}
