<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\AvailabilityStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Media\MediaStore;
use App\Support\Promoter\WorkerCatalog;
use Illuminate\Http\Request;

/**
 * Ouvrier du catalogue : profil public (sans téléphone), tarif journalier promoteur, note et disponibilité.
 *
 * @mixin Worker
 */
class CatalogWorkerResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkerTrade|null $primary */
        $primary = $this->workerTrades->firstWhere('is_primary', true) ?? $this->workerTrades->first();

        return [
            'id' => $this->id,
            'name' => trim($this->first_name.' '.$this->last_name),
            'initials' => mb_strtoupper(mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)),
            'photo_url' => $this->photoMedia ? app(MediaStore::class)->temporaryUrl($this->photoMedia) : null,
            'trade' => $primary?->trade?->only(['id', 'code', 'label']),
            'other_trades' => $this->workerTrades->reject(fn (WorkerTrade $trade) => $trade->is($primary))->map(fn (WorkerTrade $trade) => $trade->trade->only(['id', 'code', 'label']))->values()->all(),
            'experience_years' => $primary?->years_experience,
            'level' => $this->status($primary?->level),
            'commune' => $this->residenceCommune?->only(['id', 'name']),
            'daily_rate' => app(WorkerCatalog::class)->promoterRate($this->resource),
            'rating' => ['average' => $this->rating_avg !== null ? (float) $this->rating_avg : null, 'count' => (int) $this->rating_count],
            'missions_completed' => (int) $this->missions_completed,
            'availability' => $this->status($this->availability_status),
            'available' => $this->availability_status === AvailabilityStatus::Disponible,
        ];
    }
}
