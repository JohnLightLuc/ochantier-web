<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Compétence propre à un métier, exigée par les besoins et déclarée par les ouvriers.
 */
class Skill extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'trade_id', 'code', 'label', 'description', 'sort_order', 'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return BelongsToMany<Worker, $this>
     */
    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'worker_skills', 'skill_id', 'worker_id')
            ->withPivot('level', 'comment');
    }

    /**
     * @return BelongsToMany<Need, $this>
     */
    public function needs(): BelongsToMany
    {
        return $this->belongsToMany(Need::class, 'need_skills', 'skill_id', 'need_id')
            ->withPivot('is_required');
    }
}
