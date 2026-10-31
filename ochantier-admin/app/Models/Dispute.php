<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Litige : procédure contradictoire entre un demandeur et une partie mise en cause (recevabilité, déclarations,
 * preuves, médiation, décision motivée, exécution par les modules concernés, recours).
 *
 * Avis & litiges ne modifie aucun solde : les mesures financières, les remplacements et les corrections de pointage
 * sont des demandes transmises à Paiements, Affectations et Pointages, qui les exécutent.
 */
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'subject', 'category', 'subcategory', 'description', 'facts_at', 'opened_at', 'source', 'source_reference', 'claimant', 'respondent',
        'site_id', 'promoter_id', 'worker_id', 'operation_reference', 'assignment_reference', 'prestation', 'trade', 'amount', 'wish', 'urgent', 'priority',
        'severity', 'priority_reason', 'owner', 'status', 'qualified_at', 'responded_at', 'first_response_hours', 'phase_started_at', 'suspension', 'decided_at',
        'resolved_at', 'closed_at', 'reopen_count', 'admissibility', 'inadmissibility', 'parties', 'declarations', 'proofs', 'mediation', 'decision',
        'appeal_decision', 'financial_requests', 'replacements', 'attendance_requests', 'appeals', 'notes',
    ];

    public const STATUSES = ['Brouillon', 'Soumis', 'Reçu', 'À qualifier', 'Recevabilité en cours', 'Non recevable', 'Ouvert', 'Affecté', 'Analyse en cours', 'Informations demandées', 'Réponse de la partie adverse attendue', 'Médiation proposée', 'Médiation en cours', 'Accord trouvé', 'Décision en préparation', 'Décision rendue', 'En attente d’exécution', 'Résolu', 'Clôturé', 'Recours ouvert', 'Réexamen', 'Annulé', 'Archivé'];

    /**
     * Statuts d’un litige qui n’est plus actif.
     *
     * @var array<int, string>
     */
    public const INACTIVE = ['Résolu', 'Clôturé', 'Non recevable', 'Annulé', 'Archivé', 'Brouillon'];

    public const FINAL = ['Résolu', 'Clôturé', 'Non recevable', 'Annulé', 'Archivé'];

    public const EARLY = ['Brouillon', 'Soumis', 'Reçu', 'À qualifier', 'Recevabilité en cours'];

    public const PRIORITIES = ['Faible', 'Normale', 'Élevée', 'Urgente', 'Critique'];

    public const SEVERITIES = ['Mineure', 'Modérée', 'Majeure', 'Grave', 'Critique'];

    public const PRIORITY_TONES = ['Faible' => 'n', 'Normale' => 'b', 'Élevée' => 'a', 'Urgente' => 'o', 'Critique' => 'r'];

    public const SEVERITY_TONES = ['Mineure' => 'n', 'Modérée' => 'b', 'Majeure' => 'a', 'Grave' => 'o', 'Critique' => 'r'];

    /**
     * Phase SLA de chaque statut : clé du délai (Paramètres) et libellé.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PHASES = [
        'Soumis' => ['ack', 'Accusé de réception'], 'Reçu' => ['ack', 'Accusé de réception'], 'À qualifier' => ['qualif', 'Qualification'], 'Recevabilité en cours' => ['qualif', 'Qualification'],
        'Ouvert' => ['trait', 'Traitement'], 'Affecté' => ['trait', 'Traitement'], 'Analyse en cours' => ['trait', 'Traitement'], 'Informations demandées' => ['trait', 'Traitement'],
        'Réponse de la partie adverse attendue' => ['rep', 'Réponse de la partie adverse'], 'Médiation proposée' => ['med', 'Médiation'], 'Médiation en cours' => ['med', 'Médiation'],
        'Accord trouvé' => ['exec', 'Exécution'], 'Décision en préparation' => ['dec', 'Décision'], 'Décision rendue' => ['exec', 'Exécution'], 'En attente d’exécution' => ['exec', 'Exécution'],
        'Recours ouvert' => ['rec', 'Recours'], 'Réexamen' => ['rec', 'Recours'],
    ];

    /**
     * Contrôles de recevabilité : clé → libellé.
     *
     * @var array<string, string>
     */
    public const ADMISSIBILITY = ['identity' => 'identité du demandeur', 'relation' => 'relation avec la prestation', 'chantier' => 'existence du chantier', 'aff' => 'existence de l’affectation', 'delai' => 'délai de réclamation', 'desc' => 'description suffisante', 'pieces' => 'pièces minimales', 'noDup' => 'absence de doublon', 'noFinal' => 'absence de décision définitive identique'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facts_at' => 'date', 'opened_at' => 'date', 'qualified_at' => 'date', 'responded_at' => 'date', 'phase_started_at' => 'date', 'decided_at' => 'date', 'resolved_at' => 'date', 'closed_at' => 'date',
            'claimant' => 'array', 'respondent' => 'array', 'urgent' => 'boolean', 'admissibility' => 'array', 'parties' => 'array', 'declarations' => 'array', 'proofs' => 'array', 'mediation' => 'array',
            'decision' => 'array', 'appeal_decision' => 'array', 'financial_requests' => 'array', 'replacements' => 'array', 'attendance_requests' => 'array', 'appeals' => 'array', 'notes' => 'array',
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

    public static function today(): CarbonImmutable
    {
        return Payment::referenceDate();
    }

    public static function statusTone(string $status): string
    {
        return match (true) {
            in_array($status, ['Résolu', 'Clôturé', 'Accord trouvé'], true) => 'g',
            in_array($status, ['Non recevable', 'Annulé', 'Archivé', 'Brouillon'], true) => 'n',
            in_array($status, ['Recours ouvert', 'Réexamen', 'Informations demandées'], true) => 'o',
            in_array($status, ['Médiation proposée', 'Médiation en cours'], true) => 'p',
            in_array($status, ['Décision rendue', 'En attente d’exécution', 'Décision en préparation'], true) => 'b',
            default => 'a',
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, self::INACTIVE, true);
    }

    public function isEarly(): bool
    {
        return in_array($this->status, self::EARLY, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function isQualified(): bool
    {
        return $this->qualified_at !== null;
    }

    public function isResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Date d’issue (résolution, clôture, non-recevabilité ou annulation) pour la base « date de résolution ».
     */
    public function issuedAt(): ?string
    {
        return ($this->resolved_at ?? $this->closed_at)?->format('Y-m-d') ?? match ($this->status) {
            'Non recevable' => $this->opened_at->addDay()->format('Y-m-d'),
            'Annulé' => $this->opened_at->addDays(5)->format('Y-m-d'),
            default => null,
        };
    }

    /**
     * Respect du délai de la phase en cours (délais de Paramètres), sauf suspension motivée.
     *
     * @return array{status: string, tone: string, phase: string, key: string|null, start: string|null, due: string|null, left: int|null, used: int|null, total: int|null, text: string, percent: int}
     */
    public function sla(): array
    {
        $phase = self::PHASES[$this->status] ?? null;
        if (! $phase || ! $this->phase_started_at) {
            return ['status' => 'Terminé', 'tone' => 'n', 'phase' => '—', 'key' => null, 'start' => null, 'due' => null, 'left' => null, 'used' => null, 'total' => null, 'text' => 'Terminé', 'percent' => 100];
        }
        $total = (int) config('admin.quality.sla')[$phase[0]];
        $start = CarbonImmutable::parse($this->phase_started_at);
        $due = $start->addDays($total);
        $t = self::today();
        $used = (int) $start->diffInDays($t, false);
        $left = (int) $t->diffInDays($due, false);
        $ratio = $total ? $left / $total : 0;
        $status = match (true) {
            (bool) $this->suspension => 'Suspendu avec motif',
            $left < 0 => 'En retard',
            $ratio < 0.25 || $left === 0 => 'Bientôt en retard',
            $ratio < 0.5 => 'À surveiller',
            default => 'Dans les délais',
        };

        return [
            'status' => $status, 'tone' => ['En retard' => 'r', 'Bientôt en retard' => 'o', 'À surveiller' => 'a', 'Dans les délais' => 'g', 'Suspendu avec motif' => 'n'][$status],
            'phase' => $phase[1], 'key' => $phase[0], 'start' => $start->format('Y-m-d'), 'due' => $due->format('Y-m-d'), 'left' => $left, 'used' => $used, 'total' => $total,
            'text' => $this->suspension ? "Suspendu · {$this->suspension}" : ($left < 0 ? 'Dépassé de '.-$left.' j' : "{$left} j restant(s)"),
            'percent' => (int) min(100, max(0, round($used / max(1, $total) * 100))),
        ];
    }

    /**
     * Exposition financière : montant contesté, fonds suspendus, réglé, libéré, net et montants potentiels.
     *
     * @return array<string, int|bool>
     */
    public function exposure(): array
    {
        $active = $this->isActive();
        $executed = fn (array $f): bool => $f['status'] === 'Exécutée';
        $sum = fn (callable $filter): int => (int) collect($this->financial_requests)->filter($filter)->sum('amount');
        $suspended = $sum(fn (array $f): bool => str_starts_with($f['type'], 'Suspension') && $executed($f) && ! ($f['released'] ?? false));
        $settled = $sum(fn (array $f): bool => $executed($f) && ! str_starts_with($f['type'], 'Suspension'));
        $released = $sum(fn (array $f): bool => $f['type'] === 'Libération d’un paiement' && $executed($f));

        return [
            'active' => $active, 'amount' => $active ? $this->amount : 0, 'suspended' => $active ? $suspended : 0, 'settled' => $settled, 'released' => $released,
            'net' => $active ? max(0, $this->amount - $settled - $released) : 0,
            'refunds' => $active ? $sum(fn (array $f): bool => str_starts_with($f['type'], 'Remboursement') && ! $executed($f)) : 0,
            'adjustments' => $active ? $sum(fn (array $f): bool => (bool) preg_match('/^(Ajustement|Retenue)/u', $f['type']) && ! $executed($f)) : 0,
            'commissions' => $active ? $sum(fn (array $f): bool => str_contains($f['type'], 'commission') && ! $executed($f)) : 0,
            'done' => $sum($executed),
        ];
    }

    /**
     * Demandes transmises aux autres modules et pas encore exécutées.
     */
    public function pendingRequests(): int
    {
        return collect($this->financial_requests)->reject(fn (array $f): bool => in_array($f['status'], ['Exécutée', 'Refusée'], true))->count()
            + collect($this->replacements)->reject(fn (array $r): bool => (bool) preg_match('/confirmé|Exécut/u', $r['status']))->count()
            + collect($this->attendance_requests)->reject(fn (array $r): bool => str_contains($r['status'], 'Corrigée'))->count();
    }

    /**
     * Priorité automatique : sécurité (+3), fraude (+2), montant (+1 ou +2), interruption du chantier (+2),
     * ancienneté (+1), récidive (+1).
     *
     * @return array{level: string, why: string, score: int}
     */
    public static function autoPriority(string $category, int $amount, string $openedAt, bool $recidive): array
    {
        $score = 0;
        $why = [];
        $add = function (int $points, string $reason) use (&$score, &$why): void {
            $score += $points;
            $why[] = $reason;
        };
        if ($category === 'Sécurité') {
            $add(3, 'sécurité / risque humain');
        }
        if (in_array($category, ['Fraude présumée', 'Usurpation', 'Document falsifié'], true)) {
            $add(2, 'fraude');
        }
        if ($amount > 300000) {
            $add(2, 'montant > 300 000 FCFA');
        } elseif ($amount > 100000) {
            $add(1, 'montant > 100 000 FCFA');
        }
        if (in_array($category, ['Abandon de chantier', 'Remplacement', 'Retard d’exécution'], true)) {
            $add(2, 'interruption du chantier');
        }
        if (CarbonImmutable::parse($openedAt)->diffInDays(self::today()) > 30) {
            $add(1, 'ancienneté');
        }
        if ($recidive) {
            $add(1, 'récidive');
        }
        $level = match (true) {
            $score >= 5 => 'Critique',
            $score >= 4 => 'Urgente',
            $score >= 3 => 'Élevée',
            $score >= 1 => 'Normale',
            default => 'Faible',
        };

        return ['level' => $level, 'why' => implode(', ', $why) ?: 'aucun facteur aggravant', 'score' => $score];
    }

    /**
     * Change le statut (début d’une nouvelle phase SLA, dates de résolution et de clôture) et le trace.
     */
    public function moveTo(string $status, string $comment = '', string $category = 'statut'): void
    {
        $old = $this->status;
        $t = self::today();
        $this->status = $status;
        $this->phase_started_at = $t;
        if (in_array($status, ['Résolu', 'Accord trouvé'], true) && ! $this->resolved_at) {
            $this->resolved_at = $t;
        }
        if ($status === 'Clôturé') {
            $this->closed_at = $t;
        }
        $this->save();
        $this->log($category, 'a changé le statut', $old, $status, $comment);
    }

    /**
     * Met à jour un participant (rôle) dans la liste des parties.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateParty(string $role, array $changes): void
    {
        $this->parties = array_map(fn (array $p): array => $p['role'] === $role ? [...$p, ...$changes] : $p, $this->parties);
    }

    public function log(string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, ?string $origin = null): QualityActivity
    {
        return QualityActivity::record($this->reference, $category, $action, $old, $new, $comment, $author, $origin);
    }
}
