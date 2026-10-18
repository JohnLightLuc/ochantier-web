<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/**
 * Clé d’idempotence d’une requête (rejeu sans double effet).
 */
#[Fillable([
    'scope', 'key', 'actor_type', 'actor_id', 'request_hash', 'response_status', 'response_body', 'expires_at',
])]
#[Hidden(['request_hash'])]
#[WithoutIncrementing]
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

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
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
