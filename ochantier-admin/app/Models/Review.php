<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avis d’un promoteur sur un ouvrier ou une équipe contractuelle (ou évaluation interne), rattaché à une prestation
 * payée. La note et le texte de l’auteur ne sont jamais modifiés par l’administration : elle publie, masque,
 * rejette, restaure ou demande une correction.
 *
 * Note globale = Σ (note × pondération) ÷ Σ pondérations des critères notés (pondérations de Paramètres).
 */
#[Fillable([
    'reference', 'kind', 'author', 'author_type', 'promoter_id', 'promoter_name', 'site_id', 'worker_id', 'target', 'target_type', 'team',
    'operation_reference', 'assignment_reference', 'trade', 'prestation', 'period_from', 'period_to', 'date', 'weights_version', 'criteria',
    'comment', 'positives', 'negatives', 'recommends', 'proofs', 'publish_consent', 'sincere', 'verification', 'status', 'visibility', 'reply',
    'detections', 'reports', 'is_test', 'duplicate_of', 'fraud', 'eligibility', 'device', 'moderation', 'modification_request', 'dispute_reference',
])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const STATUSES = ['Brouillon', 'Soumis', 'En cours de vérification', 'Vérifié', 'En attente de modération', 'Publié', 'Masqué temporairement', 'Signalé', 'Contesté', 'En réexamen', 'Rejeté', 'Annulé', 'Archivé', 'Restauré'];

    /**
     * Statuts comptés dans la note moyenne.
     *
     * @var array<int, string>
     */
    public const COUNTED = ['Publié', 'Restauré', 'Signalé', 'Contesté', 'En réexamen'];

    public const PENDING = ['Soumis', 'En cours de vérification', 'En attente de modération'];

    public const TONES = ['Brouillon' => 'n', 'Soumis' => 'b', 'En cours de vérification' => 'b', 'Vérifié' => 'g', 'En attente de modération' => 'a', 'Publié' => 'g', 'Masqué temporairement' => 'o', 'Signalé' => 'r', 'Contesté' => 'o', 'En réexamen' => 'a', 'Rejeté' => 'r', 'Annulé' => 'n', 'Archivé' => 'n', 'Restauré' => 'g'];

    /**
     * Contrôles d’éligibilité : clé → libellé.
     *
     * @var array<string, string>
     */
    public const ELIGIBILITY = ['auth' => 'Auteur authentifié', 'linked' => 'Auteur lié à la prestation', 'affConf' => 'Affectation confirmée', 'done' => 'Prestation terminée', 'ptClosed' => 'Pointages clôturés', 'target' => 'Bonne cible (ouvrier ou équipe)', 'window' => 'Période d’évaluation respectée', 'noDup' => 'Aucun avis actif identique', 'self' => 'Pas d’autoévaluation ni de saisie par un administrateur'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'team' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'date' => 'date',
            'criteria' => 'array',
            'proofs' => 'array',
            'publish_consent' => 'boolean',
            'sincere' => 'boolean',
            'reply' => 'array',
            'detections' => 'array',
            'reports' => 'array',
            'is_test' => 'boolean',
            'fraud' => 'boolean',
            'eligibility' => 'array',
            'moderation' => 'array',
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Promoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    /**
     * @return HasMany<QualityActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(QualityActivity::class, 'subject_reference', 'reference')->latest('occurred_at')->latest('id');
    }

    /**
     * Critères pondérés du type d’avis : [clé, libellé, pondération].
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    public function criteriaDefinitions(): array
    {
        return config('admin.quality.criteria')[$this->kind === 'Équipe' ? 'Équipe' : 'Ouvrier'];
    }

    public function score(): ?float
    {
        $weights = 0;
        $sum = 0;
        foreach ($this->criteriaDefinitions() as [$key, , $weight]) {
            if ($note = $this->criteria[$key] ?? null) {
                $weights += $weight;
                $sum += $note * $weight;
            }
        }

        return $weights ? round($sum / $weights, 2) : null;
    }

    public function isEligible(): bool
    {
        return ! in_array(false, $this->eligibility, true);
    }

    /**
     * Compté dans la note moyenne : éligible, publié (ou signalé, contesté, en réexamen), ni test, ni doublon, ni fraude.
     */
    public function isCounted(): bool
    {
        return ! $this->is_test && ! $this->duplicate_of && ! $this->fraud && $this->isEligible() && in_array($this->status, self::COUNTED, true);
    }

    public function log(string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, ?string $origin = null): QualityActivity
    {
        return QualityActivity::record($this->reference, $category, $action, $old, $new, $comment, $author, $origin);
    }
}
