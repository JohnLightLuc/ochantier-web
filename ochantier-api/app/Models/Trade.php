<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Métiers, spécialités, compétences.
 */
class Trade extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code', 'label', 'description', 'sort_order', 'is_active', 'accepts_quotes', 'archived_at',
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
            'accepts_quotes' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Specialty, $this>
     */
    public function specialties(): HasMany
    {
        return $this->hasMany(Specialty::class);
    }

    /**
     * @return HasMany<Skill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    /**
     * @return HasMany<DocumentType, $this>
     */
    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    /**
     * @return HasMany<WorkerTrade, $this>
     */
    public function workerTrades(): HasMany
    {
        return $this->hasMany(WorkerTrade::class);
    }

    /**
     * @return HasMany<WorkerCertification, $this>
     */
    public function workerCertifications(): HasMany
    {
        return $this->hasMany(WorkerCertification::class);
    }

    /**
     * @return HasMany<Need, $this>
     */
    public function needs(): HasMany
    {
        return $this->hasMany(Need::class);
    }
}
