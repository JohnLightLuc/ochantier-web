<?php

namespace App\Models;

use Database\Factories\FinanceArchiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission archivée d’une période clôturée, antérieure à l’historique du module Paiements : montants figés
 * (jours × tarif, taux Y/Z de la règle alors en vigueur) et statuts finaux du paiement et du reversement.
 * Les opérations de test sont conservées mais exclues des indicateurs.
 */
class FinanceArchive extends Model
{
    /** @use HasFactory<FinanceArchiveFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'operation_reference', 'external_reference', 'site_id', 'worker_id', 'type', 'is_test', 'service_date', 'initiated_at', 'paid_at',
        'collected_at', 'payout_at', 'reconciled_at', 'days', 'daily_rate', 'promoter_rate', 'worker_rate', 'method', 'provider', 'worker_method',
        'pay_status', 'payout_status', 'commission_status', 'reconciliation', 'attempts', 'message', 'refunds', 'rule_version', 'responsible',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'service_date' => 'date',
            'initiated_at' => 'date',
            'paid_at' => 'date',
            'collected_at' => 'date',
            'payout_at' => 'date',
            'reconciled_at' => 'date',
            'refunds' => 'array',
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
