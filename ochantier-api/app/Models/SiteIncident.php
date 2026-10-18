<?php

namespace App\Models;

use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use App\Enums\SiteIncidentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Incident survenu sur un chantier (accident, sécurité, matériel…).
 */
#[Fillable([
    'reference', 'site_id', 'mission_id', 'occurred_at', 'incident_type', 'severity', 'description',
    'reported_by_type', 'reported_by_id', 'status', 'resolution', 'resolved_by_admin_id', 'resolved_at',
])]
class SiteIncident extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'incident_type' => SiteIncidentType::class,
            'severity' => SiteIncidentSeverity::class,
            'status' => SiteIncidentStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
