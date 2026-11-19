<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part nette réunie dans un versement à un professionnel (une ligne du registre des commissions).
 */
class ContractorPayoutItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['contractor_payout_id', 'source_key', 'reference', 'label', 'site_label', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<ContractorPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(ContractorPayout::class, 'contractor_payout_id');
    }
}
