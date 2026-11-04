<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des ouvriers du Back Office.
 *
 * @mixin Worker
 */
class WorkerListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkerTrade|null $primary */
        $primary = $this->workerTrades->firstWhere('is_primary', true) ?? $this->workerTrades->first();
        $commercial = $this->currentCommercial?->user;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'phone' => PhoneNumber::of($this->resource)?->formatted(),
            'photo_url' => $this->photoMedia ? app(MediaStore::class)->temporaryUrl($this->photoMedia) : null,
            'trade' => $primary?->trade?->only(['id', 'code', 'label']),
            'experience_years' => $primary?->years_experience,
            'level' => $this->status($primary?->level),
            'daily_rate' => $this->estimated_daily_rate,
            'rating' => ['average' => $this->rating_avg !== null ? (float) $this->rating_avg : null, 'count' => (int) $this->rating_count],
            'availability' => $this->status($this->availability_status),
            'status' => $this->status($this->verification_status),
            'commune' => $this->residenceCommune?->only(['id', 'name']),
            'commercial' => $commercial ? ['id' => $this->current_commercial_id, 'full_name' => trim($commercial->first_name.' '.$commercial->last_name)] : null,
            'expired_documents_count' => (int) ($this->expired_documents_count ?? 0),
            'identity_mismatch' => (bool) $this->identity_mismatch,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
        ];
    }
}
