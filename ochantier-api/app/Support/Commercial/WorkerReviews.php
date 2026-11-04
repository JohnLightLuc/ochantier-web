<?php

namespace App\Support\Commercial;

use App\Enums\DocumentVerificationStatus;
use App\Enums\VerificationCorrectionPriority;
use App\Enums\VerificationReviewStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\Document;
use App\Models\VerificationReview;
use App\Models\Worker;
use App\Support\Documents\DocumentStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Décisions du Back office sur un dossier ouvrier transmis : validation (commission confirmée), demande de
 * correction (pièce ou information à reprendre par le commercial) ou refus (commission annulée). Utilisé par le
 * Back office (WorkerVerification, avec l’administrateur décideur) et par la simulation locale (sans administrateur).
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

    public function validate(Worker $worker, string $reason = 'Dossier conforme', ?Admin $admin = null): void
    {
        $earning = DB::transaction(function () use ($worker, $reason, $admin) {
            $this->decide($worker, WorkerVerificationStatus::Valide, VerificationReviewStatus::Valide, $reason, $admin);
            $worker->forceFill(['validated_at' => now(), 'validated_by_admin_id' => $admin?->getKey()])->save();

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

        $this->requestCorrections($worker, [['document' => $document, 'field' => $field, 'reason' => $reason]], $reason);
    }

    /**
     * Demande de corrections : chaque correction porte sur une pièce (rejetée avec le motif) ou sur une information
     * du dossier. Le commercial reprend le dossier et le renvoie.
     *
     * @param  list<array{document?: Document|null, field?: string|null, reason: string}>  $corrections
     */
    public function requestCorrections(Worker $worker, array $corrections, ?string $message = null, ?Admin $admin = null): void
    {
        DB::transaction(function () use ($worker, $corrections, $message, $admin): void {
            $review = $this->decide($worker, WorkerVerificationStatus::ACorriger, VerificationReviewStatus::ACorriger, $message ?? $corrections[0]['reason'], $admin);

            foreach ($corrections as $correction) {
                $document = $correction['document'] ?? null;

                if ($document && $admin) {
                    $document->forceFill(['verification_status' => DocumentVerificationStatus::Rejete, 'rejection_reason' => $correction['reason'], 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();
                }

                $review->verificationCorrections()->create([
                    'target_field' => $correction['field'] ?? ($document ? 'documents' : null),
                    'document_id' => $document?->getKey(),
                    'reason' => $correction['reason'],
                    'priority' => VerificationCorrectionPriority::Normale,
                    'due_at' => now()->addDays(7),
                ]);
            }
        });

        $this->notifier->correctionRequested($worker, array_column($corrections, 'reason'));
    }

    public function refuse(Worker $worker, string $reason, ?Admin $admin = null): void
    {
        DB::transaction(function () use ($worker, $reason, $admin): void {
            $this->decide($worker, WorkerVerificationStatus::Refuse, VerificationReviewStatus::Refuse, $reason, $admin);
            $this->earnings->cancel($worker);
        });

        $this->notifier->refused($worker, $reason);
    }

    private function decide(Worker $worker, WorkerVerificationStatus $status, VerificationReviewStatus $decision, string $reason, ?Admin $admin): VerificationReview
    {
        if (! in_array($worker->verification_status, self::DECIDABLE, true)) {
            throw new RuntimeException("Dossier {$worker->reference} : seul un dossier transmis peut être contrôlé ({$worker->verification_status->label()}).");
        }

        $review = $worker->verificationReviews()->orderByDesc('submission_number')->firstOrFail();
        $review->update(['status' => $decision, 'decision_reason' => $reason, 'decided_by_admin_id' => $admin?->getKey(), 'decided_at' => now()]);

        $worker->statusHistories()->create([
            'from_status' => $worker->verification_status->value,
            'to_status' => $status->value,
            'reason' => $reason,
            'actor_type' => $admin ? $admin->getMorphClass() : 'systeme',
            'actor_id' => $admin?->getKey(),
        ]);
        $worker->forceFill(['verification_status' => $status, 'lock_version' => $worker->lock_version + 1])->save();

        return $review;
    }
}
