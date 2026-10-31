<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réclamation : demande initiale d’un promoteur ou d’un ouvrier, traitée simplement ou transformée en litige après
 * qualification.
 */
class Claim extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['reference', 'claimant', 'other_party', 'site_id', 'assignment_reference', 'operation_reference', 'category', 'subject', 'description', 'date', 'priority', 'owner', 'due_date', 'status', 'dispute_id', 'resolution', 'review_reference'];

    public const STATUSES = ['Brouillon', 'Soumise', 'Reçue', 'À qualifier', 'Information demandée', 'Traitement simple', 'Résolue', 'Rejetée', 'Transformée en litige', 'Clôturée'];

    public const CLOSED = ['Résolue', 'Clôturée', 'Rejetée', 'Transformée en litige'];

    public const TONES = ['Résolue' => 'g', 'Clôturée' => 'n', 'Rejetée' => 'r', 'Transformée en litige' => 'p', 'Information demandée' => 'o', 'Traitement simple' => 'b', 'À qualifier' => 'a', 'Reçue' => 'a', 'Soumise' => 'b', 'Brouillon' => 'n'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimant' => 'array',
            'other_party' => 'array',
            'date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return HasMany<QualityActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(QualityActivity::class, 'subject_reference', 'reference')->latest('occurred_at')->latest('id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED, true);
    }

    public function log(string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, ?string $origin = null): QualityActivity
    {
        return QualityActivity::record($this->reference, $category, $action, $old, $new, $comment, $author, $origin);
    }
}
