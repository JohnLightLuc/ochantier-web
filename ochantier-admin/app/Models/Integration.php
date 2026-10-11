<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Service connecté (paiement, SMS, e-mail, push, cartographie, stockage…). Le secret est chiffré et jamais
 * affiché en clair : seuls ses quatre derniers caractères sont montrés.
 */
#[Fillable(['name', 'type', 'status', 'environment', 'endpoint', 'client_id', 'secret', 'expires_on', 'latency', 'error_rate', 'tested_at', 'test_ok', 'last_result'])]
#[Hidden(['secret'])]
class Integration extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'expires_on' => 'date',
            'tested_at' => 'datetime',
            'test_ok' => 'boolean',
            'error_rate' => 'float',
        ];
    }

    public function maskedClientId(): string
    {
        return mb_substr($this->client_id, 0, 3).'••••'.mb_substr($this->client_id, -2);
    }

    public function maskedSecret(): string
    {
        return '•••• '.mb_substr($this->secret, -4);
    }
}
