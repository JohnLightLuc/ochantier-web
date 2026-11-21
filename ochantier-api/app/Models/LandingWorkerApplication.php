<?php

namespace App\Models;

use App\Enums\LandingApplicationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Candidature « Devenir ouvrier » reçue par le site public, avec sa pièce d’identité et sa photo.
 */
class LandingWorkerApplication extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'first_name', 'last_name', 'phone', 'phone_digits', 'email', 'birth_date', 'gender', 'photo_media_id',
        'identity_media_id', 'trade_label', 'trade_id', 'daily_rate', 'experience_years', 'level', 'skills', 'certifications',
        'bio', 'commune_label', 'commune_id', 'neighborhood', 'mobility', 'availability', 'available_days', 'payout_method',
        'payout_number', 'target_site', 'target_site_commune', 'status', 'refusal_reason', 'worker_id', 'handled_by_admin_id',
        'consented_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'daily_rate' => 'integer',
            'experience_years' => 'integer',
            'skills' => 'array',
            'certifications' => 'array',
            'available_days' => 'array',
            'status' => LandingApplicationStatus::class,
            'consented_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function photoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'photo_media_id');
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function identityMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'identity_media_id');
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function handledByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }
}
