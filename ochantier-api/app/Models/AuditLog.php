<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal d’audit générique, en ajout seul (table partitionnée par mois).
 */
class AuditLog extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'module', 'subject_type', 'subject_id', 'before_values', 'after_values',
        'ip_address', 'user_agent', 'device_id', 'correlation_id',
    ];

    public const UPDATED_AT = null;

    /**
     * Horodatage à la microseconde : les actions d’une même seconde restent ordonnées.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
