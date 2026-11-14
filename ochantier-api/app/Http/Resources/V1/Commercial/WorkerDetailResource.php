<?php

namespace App\Http\Resources\V1\Commercial;

use App\Enums\WorkerVerificationStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Resource;
use App\Models\CommercialEarning;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Commercial\CommercialWorkers;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Fiche d’un ouvrier transmis par le commercial (« Voir le dossier », « Voir le profil ») : état du dossier,
 * identité, activité, contact d’urgence, pièces, commission et, une fois validé, son activité sur la plateforme.
 * Le dossier se corrige toujours par son prospect (prospect_id).
 *
 * @mixin Worker
 */
class WorkerDetailResource extends Resource
{
    /**
     * Relations chargées pour la fiche.
     */
    public const WITH = [
        'workerTrades.trade', 'residenceCommune', 'residenceNeighborhood', 'photoMedia', 'specialties', 'skills', 'references',
        'verificationReviews.verificationCorrections.document.documentType', 'commercialEarnings',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workers = app(CommercialWorkers::class);
        $trades = $this->workerTrades->sortByDesc('is_primary')->values();
        $primary = $trades->firstWhere('is_primary', true) ?? $trades->first();
        $earning = $this->commercialEarnings
            ->where('commercial_id', $this->current_commercial_id)
            ->sortByDesc('event_at')
            ->first();

        return [
            ...$workers->row($this->resource),
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            ...PhoneNumber::of($this->resource)?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null],
            'photo_url' => $this->photoMedia ? app(MediaStore::class)->temporaryUrl($this->photoMedia) : null,
            'neighborhood' => $this->residenceNeighborhood?->only(['id', 'name']),
            'identity' => [
                'gender' => $this->status($this->gender),
                'birth_date' => $this->date($this->birth_date),
                'birth_place' => $this->birth_place,
            ],
            'activity' => [
                'daily_rate' => $this->estimated_daily_rate,
                'level' => $this->status($primary?->level),
                'experience_years' => $primary?->years_experience,
                'trades' => $trades->map(fn (WorkerTrade $trade) => [
                    'trade' => $trade->trade?->only(['id', 'code', 'label']),
                    'is_primary' => $trade->is_primary,
                    'level' => $this->status($trade->level),
                    'experience_years' => $trade->years_experience,
                ])->all(),
                'specialties' => $this->specialties->map->only(['id', 'label'])->values()->all(),
                'skills' => $this->skills->map->only(['id', 'label'])->values()->all(),
                'experiences' => $this->references->pluck('project_name')->values()->all(),
            ],
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                ...PhoneNumber::of($this->resource, 'emergency_contact_')?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null],
            ],
            'documents' => DocumentResource::collection(app(DocumentStore::class)->active($this->resource))->resolve(),
            'submission' => $workers->submission($this->resource),
            'commission' => $earning ? $this->commission($earning) : null,
            'performance' => $this->verification_status === WorkerVerificationStatus::Valide ? [
                'validated_at' => $this->validated_at?->toIso8601String(),
                'availability' => $this->status($this->availability_status),
                'rating_avg' => $this->rating_avg !== null ? (float) $this->rating_avg : null,
                'rating_count' => (int) $this->rating_count,
                'missions_completed' => (int) $this->missions_completed,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commission(CommercialEarning $earning): array
    {
        return [
            'amount' => $earning->amount,
            'status' => $this->status($earning->status),
        ];
    }
}
