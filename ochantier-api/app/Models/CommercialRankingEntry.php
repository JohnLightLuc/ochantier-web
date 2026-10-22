<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rang d’un commercial dans un classement mensuel.
 */
class CommercialRankingEntry extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ranking_id', 'commercial_id', 'rank', 'score', 'metrics',
    ];

    public $timestamps = false;

    public $incrementing = false;

    /**
     * Clé primaire composite : le modèle n’a pas de clé simple.
     *
     * @var string|null
     */
    protected $primaryKey = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CommercialRanking, $this>
     */
    public function ranking(): BelongsTo
    {
        return $this->belongsTo(CommercialRanking::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }
}
