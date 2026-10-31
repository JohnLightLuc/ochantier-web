<?php

namespace App\Models;

use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident signalé sur un chantier (accident, retard d’approvisionnement, arrêt administratif…).
 */
class SiteIncident extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['reference', 'occurred_on', 'type', 'severity', 'description', 'reported_by', 'status', 'resolution'];

    /**
     * Types d’incident proposés au signalement.
     *
     * @var array<int, string>
     */
    public const TYPES = ['Accident', 'Retard d’approvisionnement', 'Absence massive', 'Intempéries', 'Litige', 'Vol ou dégradation', 'Arrêt administratif'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'severity' => SiteIncidentSeverity::class,
            'status' => SiteIncidentStatus::class,
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
     * Prochaine référence « INC-xxx ».
     */
    public static function nextReference(): string
    {
        $last = static::query()->pluck('reference')->map(fn (string $r): int => (int) substr($r, 4))->max() ?? 399;

        return 'INC-'.max(400, $last + 1);
    }
}
