<?php

namespace App\Support\Commercial;

use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialProfile;
use App\Models\VerificationCorrection;
use App\Models\VerificationReview;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Ouvriers » : dossiers transmis par le commercial au Back office, par état (en attente, à corriger, validé,
 * refusé), avec les corrections demandées.
 */
class CommercialWorkers
{
    /**
     * Onglet => [libellé, statuts de vérification].
     *
     * @var array<string, array{0: string, 1: list<WorkerVerificationStatus>}>
     */
    public const TABS = [
        'tous' => ['Tous', [WorkerVerificationStatus::Soumis, WorkerVerificationStatus::EnControle, WorkerVerificationStatus::ACorriger, WorkerVerificationStatus::Valide, WorkerVerificationStatus::Refuse]],
        'attente' => ['En attente', CommercialPerformance::IN_REVIEW],
        'corriger' => ['À corriger', [WorkerVerificationStatus::ACorriger]],
        'valide' => ['Validés', [WorkerVerificationStatus::Valide]],
    ];

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(CommercialProfile $commercial, string $tab = 'tous', ?string $search = null): array
    {
        $base = fn () => Worker::query()
            ->where('current_commercial_id', $commercial->getKey())
            ->when($search, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('first_name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('last_name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('workerTrades.trade', fn (Builder $query) => $query->where('label', 'ilike', '%'.addcslashes($search, '%_\\').'%'))));

        $workers = $base()
            ->whereIn('verification_status', self::TABS[$tab][1])
            ->with(['workerTrades.trade', 'residenceCommune', 'verificationReviews.verificationCorrections.document.documentType'])
            ->latest('submitted_at')
            ->latest('id')
            ->get();

        return [
            'data' => $workers->map(fn (Worker $worker) => $this->row($worker))->values()->all(),
            'meta' => [
                'total' => $base()->whereIn('verification_status', self::TABS['tous'][1])->count(),
                'tabs' => collect(self::TABS)->map(fn (array $definition, string $key) => [
                    'key' => $key,
                    'label' => $definition[0],
                    'count' => $base()->whereIn('verification_status', $definition[1])->count(),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * État du dossier transmis : statut, corrections demandées et possibilité de le corriger.
     *
     * @return array<string, mixed>|null
     */
    public function submission(?Worker $worker): ?array
    {
        if (! $worker || $worker->verification_status === WorkerVerificationStatus::Brouillon) {
            return null;
        }

        $review = $this->latestReview($worker);
        $corrections = $worker->verification_status === WorkerVerificationStatus::ACorriger && $review
            ? $review->verificationCorrections->whereNull('resolved_at')->values()
            : collect();

        return [
            'worker_id' => $worker->id,
            'reference' => $worker->reference,
            'status' => $this->state($worker),
            'submitted_at' => $worker->submitted_at?->toIso8601String(),
            'submission_number' => $review?->submission_number,
            'decision_reason' => $review?->decision_reason,
            'corrections' => $corrections->map(fn (VerificationCorrection $correction) => [
                'id' => $correction->id,
                'reason' => $correction->reason,
                'target_field' => $correction->target_field,
                'document_type' => $correction->document?->documentType?->only(['code', 'label']),
                'priority' => ['value' => $correction->priority->value, 'label' => $correction->priority->label()],
            ])->all(),
            'can_correct' => $worker->verification_status === WorkerVerificationStatus::ACorriger,
        ];
    }

    public function latestReview(Worker $worker): ?VerificationReview
    {
        return $worker->verificationReviews->sortByDesc('submission_number')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Worker $worker): array
    {
        $submission = $this->submission($worker);
        $trade = $worker->workerTrades->firstWhere('is_primary', true)?->trade;
        $state = $submission['status'];

        return [
            'worker_id' => $worker->id,
            'prospect_id' => $worker->prospect_id,
            'reference' => $worker->reference,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
            'trade' => $trade?->only(['id', 'code', 'label']),
            'commune' => $worker->residenceCommune?->only(['id', 'name']),
            'status' => $state,
            'issue' => $submission['corrections'] !== [] ? implode(' ; ', array_column($submission['corrections'], 'reason')) : ($worker->verification_status === WorkerVerificationStatus::Refuse ? $submission['decision_reason'] : null),
            'date_label' => match ($state['value']) {
                'valide' => 'Validé le '.$this->date($worker->validated_at),
                'corriger' => 'À corriger depuis le '.$this->date($this->latestReview($worker)?->decided_at ?? $worker->updated_at),
                'refuse' => 'Refusé le '.$this->date($this->latestReview($worker)?->decided_at ?? $worker->updated_at),
                default => 'Transmis le '.$this->date($worker->submitted_at),
            },
            'cta' => match ($state['value']) {
                'corriger' => 'Corriger',
                'valide' => 'Voir le profil',
                default => 'Voir le dossier',
            },
            'can_correct' => $submission['can_correct'],
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function state(Worker $worker): array
    {
        return match ($worker->verification_status) {
            WorkerVerificationStatus::ACorriger => ['value' => 'corriger', 'label' => 'À corriger'],
            WorkerVerificationStatus::Valide => ['value' => 'valide', 'label' => 'Validé'],
            WorkerVerificationStatus::Refuse => ['value' => 'refuse', 'label' => 'Refusé'],
            default => ['value' => 'attente', 'label' => 'En attente'],
        };
    }

    private function date(?CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date ?? now())->locale('fr')->translatedFormat('j M Y');
    }
}
