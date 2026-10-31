<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journée de pointage attendue d’un ouvrier sur un chantier (une affectation, une date).
 *
 * L’arrivée et le départ sont enregistrés puis validés par le promoteur depuis son application ;
 * le Back-office supervise : anomalies, corrections, absences, clôture et transmission aux Paiements.
 *
 * `arrival` et `departure` : statut, heure serveur, auteur, validation, localisation, preuves et note.
 * `closure` : statut de clôture (vide, clôturée automatiquement ou manuellement, rouverte), auteur, date et justification.
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'date', 'worker_id', 'site_id', 'assignment_reference', 'planned_start', 'planned_end', 'night',
        'arrival', 'departure', 'pauses', 'absence', 'lateness', 'early_leave', 'overtime', 'closure', 'transmission',
        'reminders', 'notes', 'last_update',
    ];

    public const NOT_TRANSMITTED = 'Non transmise';

    public const TRANSMITTED = 'Transmise au calcul financier';

    public const REOPENED = 'Rouverte avec autorisation';

    /**
     * Statuts d’une arrivée (et d’un départ, au masculin) considérés comme validés ou en attente.
     * Une heure validée faisant l’objet d’une demande de correction reste l’heure de référence jusqu’à la décision.
     *
     * @var array<string, array<int, string>>
     */
    public const VALIDATED = ['arrival' => ['Validée', 'Corrigée', 'Correction demandée'], 'departure' => ['Validé', 'Corrigé', 'Correction demandée']];

    public const PENDING = ['arrival' => ['Enregistrée', 'En attente de validation'], 'departure' => ['Enregistré', 'En attente de validation']];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'night' => 'boolean',
            'arrival' => 'array',
            'departure' => 'array',
            'pauses' => 'array',
            'absence' => 'array',
            'lateness' => 'array',
            'early_leave' => 'array',
            'overtime' => 'array',
            'closure' => 'array',
            'notes' => 'array',
            'last_update' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<AttendanceAnomaly, $this>
     */
    public function anomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class)->latest('id');
    }

    /**
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class)->latest('requested_at')->latest('id');
    }

    /**
     * @return HasMany<AttendanceActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AttendanceActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Date du jour des données de démonstration (config/admin.php).
     */
    public static function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('admin.attendance_reference_date') ?? today());
    }

    /**
     * Événement vierge (arrivée ou départ).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function event(string $status, array $values = []): array
    {
        return ['status' => $status, 'time' => null, 'by' => null, 'at' => null, 'validated_by' => null, 'validated_at' => null, 'location' => null, 'proofs' => [], 'comment' => null, ...$values];
    }

    public function isClosed(): bool
    {
        return str_starts_with((string) ($this->closure['status'] ?? ''), 'Clôturée');
    }

    /**
     * Promoteur et responsable du chantier, auteurs des pointages dans l’application.
     */
    public function promoterLabel(): string
    {
        return $this->site->promoter_name.' · '.($this->site->manager ?: 'Responsable chantier');
    }

    /**
     * Ajoute un événement au journal du pointage et date la dernière mise à jour.
     */
    public function logActivity(string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, string $origin = 'Back-office'): AttendanceActivity
    {
        $this->forceFill(['last_update' => now()])->save();

        return $this->activities()->create([
            'occurred_at' => now(),
            'author' => $author ?? auth()->user()?->name ?? 'Système',
            'category' => $category,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'comment' => $comment,
            'origin' => $origin,
        ]);
    }
}
