<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Formule proposée aux promoteurs : nombre maximum d’utilisateurs et prix mensuel.
 * La formule Solo (gratuite, propriétaire seul) ne peut être ni modifiée ni supprimée.
 */
#[Fillable(['key', 'name', 'max_users', 'price', 'period', 'subtitle', 'description', 'features', 'popular', 'position'])]
class SubscriptionPlan extends Model
{
    public const SOLO = 'solo';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'popular' => 'boolean',
        ];
    }

    public function isSolo(): bool
    {
        return $this->key === self::SOLO;
    }

    public function priceLabel(): string
    {
        return $this->price ? number_format($this->price, 0, ',', ' ').' FCFA' : 'Gratuit';
    }
}
