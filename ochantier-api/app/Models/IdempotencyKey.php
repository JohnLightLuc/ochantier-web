<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Clé d’idempotence d’une requête (rejeu sans double effet).
 */
class IdempotencyKey extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'scope', 'key', 'actor_type', 'actor_id', 'request_hash', 'response_status', 'response_body', 'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['request_hash'];

    public $incrementing = false;

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
