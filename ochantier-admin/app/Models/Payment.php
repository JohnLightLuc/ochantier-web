<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opération financière : paiement d’une prestation (jours de pointage clôturés × tarif de l’affectation) ou acompte
 * d’un chantier. Le promoteur paie la prestation, sa part de commission (Y) et les frais du prestataire ;
 * l’ouvrier reçoit le net après sa part de commission (Z) et les retenues approuvées.
 *
 * Statuts suivis séparément : calcul, paiement promoteur, reversement ouvrier et rapprochement.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'date', 'type', 'worker_id', 'site_id', 'assignment_reference', 'period_start', 'period_end', 'days', 'open_days',
        'daily_rate', 'fixed_amount', 'source', 'method', 'provider', 'account', 'worker_method', 'worker_account', 'calc_status',
        'pay_status', 'payout_status', 'reconciliation', 'reconciliation_note', 'validated_by', 'validated_at', 'initiated_at', 'paid_at',
        'payout_at', 'external_reference', 'payout_reference', 'provider_message', 'payout_message', 'batch_reference', 'attempts',
        'received_amount', 'rule_version', 'responsible', 'attendance_refs', 'documents', 'reported_anomalies',
    ];

    public const DEPOSIT = 'Acompte';

    /**
     * Statuts d’un paiement promoteur reçu.
     *
     * @var array<int, string>
     */
    public const PAID = ['Réussi', 'Confirmé', 'Rapproché', 'Partiellement remboursé', 'Remboursé', 'Extourné'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'validated_at' => 'date',
            'initiated_at' => 'date',
            'paid_at' => 'date',
            'payout_at' => 'date',
            'attendance_refs' => 'array',
            'documents' => 'array',
            'reported_anomalies' => 'array',
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
     * @return HasMany<PaymentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->latest('date')->latest('id');
    }

    /**
     * @return HasMany<PaymentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(PaymentActivity::class)->latest('occurred_at')->latest('id');
    }

    public static function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('admin.attendance_reference_date') ?? today());
    }

    /**
     * Clé d’idempotence : une seule opération par affectation (ou chantier), période et type.
     */
    public function key(): string
    {
        return ($this->assignment_reference ?: "site-{$this->site_id}").'|'.$this->period_start->format('Y-m-d').'|'.$this->period_end->format('Y-m-d').'|'.$this->type;
    }

    public function isDeposit(): bool
    {
        return $this->worker_id === null;
    }

    /**
     * Ajoute un événement au journal (avec la version de la règle de commission).
     */
    public function logActivity(string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, string $origin = 'Back-office'): PaymentActivity
    {
        return $this->activities()->create([
            'occurred_at' => now(),
            'author' => $author ?? (auth()->user()?->name ?? 'Système').' · Administrateur finance',
            'category' => $category,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'comment' => trim(($comment ? "{$comment} · " : '').'règle '.$this->rule_version),
            'origin' => $origin,
        ]);
    }

    /**
     * Ajoute un document émis (reçu, justificatif, relevé…).
     */
    public function addDocument(string $prefix, string $type): void
    {
        $this->update(['documents' => [...$this->documents, ['reference' => $prefix.'-'.substr($this->reference, 3), 'type' => $type, 'date' => self::referenceDate()->format('Y-m-d'), 'status' => 'Émis', 'version' => 1]]]);
    }
}
