<?php

namespace App\Models;

use App\Casts\GeoPoint;
use App\Enums\AttendanceEventStatus;
use App\Enums\AttendanceEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Événement de pointage (arrivée ou départ) saisi par le promoteur ou un responsable autorisé.
 */
#[Fillable([
    'assignment_id', 'mission_id', 'site_id', 'worker_id', 'work_date', 'event_type', 'recorded_at',
    'device_recorded_at', 'author_user_id', 'photo_media_id', 'signature_media_id', 'location', 'location_accuracy_m',
    'distance_to_site_m', 'out_of_zone', 'device_id', 'app_version', 'status', 'superseded_by_id', 'client_uuid',
])]
class AttendanceEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'event_type' => AttendanceEventType::class,
            'recorded_at' => 'datetime',
            'device_recorded_at' => 'datetime',
            'location' => GeoPoint::class,
            'location_accuracy_m' => 'decimal:2',
            'distance_to_site_m' => 'decimal:2',
            'out_of_zone' => 'boolean',
            'status' => AttendanceEventStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function photoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function signatureMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<AttendanceEvent, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    /**
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }
}
