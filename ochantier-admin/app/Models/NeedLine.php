<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ligne d’un besoin : un métier, une spécialité, un effectif et un niveau d’expérience.
 */
class NeedLine extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['position', 'trade_group', 'trade', 'specialty', 'quantity', 'experience', 'daily_cost', 'pay_type', 'skills', 'certifications', 'own_equipment', 'tools', 'constraints', 'comment'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'certifications' => 'array',
            'own_equipment' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return HasOne<NeedOffer, $this>
     */
    public function offer(): HasOne
    {
        return $this->hasOne(NeedOffer::class);
    }

    /**
     * Montant affiché selon le mode de rémunération (journalier, hebdomadaire ou global sur la durée).
     */
    public function amountLabel(int $duration): string
    {
        $format = fn (int $v): string => number_format($v, 0, ',', ' ');

        return match (true) {
            $this->pay_type === 'À négocier' || ! $this->daily_cost => 'À négocier',
            $this->pay_type === 'Journalier' => $format($this->daily_cost).' FCFA/jour',
            $this->pay_type === 'Hebdomadaire' => $format($this->daily_cost * 6).' FCFA/semaine',
            default => $format((int) round($this->daily_cost * $duration * 6 / 7 / 5000) * 5000).' FCFA (global)',
        };
    }
}
