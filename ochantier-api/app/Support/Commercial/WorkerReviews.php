<?php

namespace App\Support\Commercial;

use App\Enums\VerificationCorrectionPriority;
use App\Enums\VerificationReviewStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Document;
use App\Models\VerificationReview;
use App\Models\Worker;
use App\Support\Documents\DocumentStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Décisions du Back office sur un dossier ouvrier transmis : validation (commission confirmée), demande de
 * correction (pièce ou information à reprendre par le commercial) ou refus (commission annulée). Utilisé par la
 * simulation locale du Back office.
 */
class WorkerReviews
{
    /**
     * Dossiers sur lesquels le Back office peut se prononcer.
     */
    private const DECIDABLE = [WorkerVerificationStatus::Soumis, WorkerVerificationStatus::EnControle];

    public function __construct(
        private CommercialEarnings $earnings,
        private CommercialNotifier $notifier,
        private DocumentStore $documents,
    ) {}

    public function validate(Worker $worker, string $reason = 'Dossier conforme'): void
    {
        $earning = DB::transaction(function () use ($worker, $reason) {
            $this->decide($worker, WorkerVerificationStatus::Valide, VerificationReviewStatus::Valide, $reason);
            $worker->forceFill(['validated_at' => now()])->save();

            return $this->earnings->confirm($worker);
        });

        $this->notifier->validated($worker, $earning?->amount);
    }

    /**
     * Demande de correction ; $documentCode désigne la pièce à remplacer (sinon une information du dossier).
     */
    public function requestCorrection(Worker $worker, string $reason, ?string $documentCode = null, ?string $field = null): void
    {
        $document = $documentCode
            ? $this->documents->active($worker)->first(fn (Document $document) => $document->documentType->code === $documentCode || ($documentCode === 'identity' && $document->documentType->is_identity))
            : null;

        if ($documentCode && ! $document) {
            throw new RuntimeException("Pièce {$documentCode} introuvable dans le dossier.");
        }

        DB::transaction(function () use ($worker, $reason, $document, $field): void {
            $review = $this->decide($worker, WorkerVerificationStatus::ACorriger, VerificationReviewStatus::ACorriger, $reason);
            $review->verificationCorrections()->create([
                'target_field' => $field ?? ($document ? 'documents' : null),
                'document_id' => $document?->getKey(),
                'reason' => $reason,
                'priority' => VerificationCorrectionPriority::Normale,
                'due_at' => now()->addDays(7),
            ]);
        });

        $this->notifier->correctionRequested($worker, [$reason]);
    }

    public function refuse(Worker $worker, string $reason): void
    {
        DB::transaction(function () use ($worker, $reason): void {
            $this->decide($worker, WorkerVerificationStatus::Refuse, VerificationReviewStatus::Refuse, $reason);
            $this->earnings->cancel($worker);
        });

        $this->notifier->refused($worker, $reason);
    }

    private function decide(Worker $worker, WorkerVerificationStatus $status, VerificationReviewStatus $decision, string $reason): VerificationReview
    {
        if (! in_array($worker->verification_status, self::DECIDABLE, true)) {
            throw new RuntimeException("Dossier {$worker->reference} : seul un dossier transmis peut être contrôlé ({$worker->verification_status->label()}).");
        }

        $review = $worker->verificationReviews()->orderByDesc('submission_number')->firstOrFail();
        $review->update(['status' => $decision, 'decision_reason' => $reason, 'decided_at' => now()]);

        $worker->statusHistories()->create(['from_status' => $worker->verification_status->value, 'to_status' => $status->value, 'reason' => $reason, 'actor_type' => 'systeme']);
        $worker->forceFill(['verification_status' => $status, 'lock_version' => $worker->lock_version + 1])->save();

        return $review;
    }
}
