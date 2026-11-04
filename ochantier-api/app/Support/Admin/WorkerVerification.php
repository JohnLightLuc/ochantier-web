<?php

namespace App\Support\Admin;

use App\Enums\DocumentVerificationStatus;
use App\Enums\VerificationReviewStatus;
use App\Enums\WorkerSanctionType;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\Document;
use App\Models\VerificationReview;
use App\Models\Worker;
use App\Models\WorkerSanction;
use App\Support\Commercial\ProspectDossier;
use App\Support\Commercial\WorkerReviews;
use App\Support\Documents\DocumentStore;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle d’un dossier ouvrier par le Back Office : pièces vérifiées une à une, puis validation (commission du
 * commercial confirmée), demande de corrections au commercial ou refus. Suspension (date de fin facultative) et
 * exclusion définitive retirent l’ouvrier du matching. Chaque action est inscrite au journal d’audit.
 */
class WorkerVerification
{
    /**
     * Dossiers sur lesquels le Back Office peut se prononcer.
     */
    public const REVIEWABLE = [WorkerVerificationStatus::Soumis, WorkerVerificationStatus::EnControle];

    public function __construct(private WorkerReviews $reviews, private DocumentStore $documents) {}

    /**
     * Pièces obligatoires pour la validation : pièce d’identité et photo d’identité. Clé => [libellé, pièce ou null].
     *
     * @return array<string, array{0: string, 1: Document|null}>
     */
    public function requiredDocuments(Worker $worker): array
    {
        $documents = $this->documents->active($worker);

        return [
            'identity' => ['Pièce d’identité', $documents->first(fn (Document $document) => $document->documentType->is_identity)],
            ProspectDossier::PHOTO_DOCUMENT => ['Photo d’identité', $documents->first(fn (Document $document) => $document->documentType->code === ProspectDossier::PHOTO_DOCUMENT)],
        ];
    }

    /**
     * Motif pour lequel le dossier ne peut pas être validé, ou null.
     */
    public function approvalRefusal(Worker $worker): ?string
    {
        if (! in_array($worker->verification_status, self::REVIEWABLE, true)) {
            return 'Le dossier n’est pas en attente de vérification (statut : '.$worker->verification_status->label().').';
        }

        $required = $this->requiredDocuments($worker);
        $missing = array_filter($required, fn (array $item) => $item[1] === null);

        if ($missing !== []) {
            return 'Pièces manquantes : '.implode(', ', array_column($missing, 0)).'.';
        }

        $unverified = array_filter($required, fn (array $item) => $item[1]->verification_status !== DocumentVerificationStatus::Valide);

        return $unverified === [] ? null : 'Pièces à vérifier avant la validation : '.implode(', ', array_column($unverified, 0)).'.';
    }

    public function approve(Worker $worker, Admin $admin, Request $request): void
    {
        if ($refusal = $this->approvalRefusal($worker)) {
            throw ValidationException::withMessages(['worker' => $refusal]);
        }

        $before = $worker->verification_status->value;
        $this->reviews->validate($worker, 'Dossier conforme', $admin);

        AdminAudit::record($admin, 'workers', 'worker.approved', $request, $worker, ['verification_status' => $before], ['verification_status' => 'valide']);
    }

    /**
     * @param  list<array{document_id?: string|null, field?: string|null, reason: string}>  $corrections
     */
    public function requestCorrections(Worker $worker, Admin $admin, array $corrections, ?string $message, Request $request): void
    {
        $this->ensureReviewable($worker);
        $before = $worker->verification_status->value;

        $this->reviews->requestCorrections($worker, array_map(fn (array $correction) => [
            'document' => isset($correction['document_id']) ? $this->document($worker, $correction['document_id']) : null,
            'field' => isset($correction['document_id']) ? null : ($correction['field'] ?? null),
            'reason' => $correction['reason'],
        ], $corrections), $message, $admin);

        AdminAudit::record($admin, 'workers', 'worker.corrections_requested', $request, $worker, ['verification_status' => $before], ['verification_status' => 'a_corriger', 'corrections' => $corrections]);
    }

    public function reject(Worker $worker, Admin $admin, string $reason, Request $request): void
    {
        $this->ensureReviewable($worker);
        $before = $worker->verification_status->value;
        $this->reviews->refuse($worker, $reason, $admin);

        AdminAudit::record($admin, 'workers', 'worker.rejected', $request, $worker, ['verification_status' => $before], ['verification_status' => 'refuse', 'reason' => $reason]);
    }

    public function approveDocument(Worker $worker, Document $document, Admin $admin, Request $request): Document
    {
        return $this->decideDocument($worker, $document, $admin, DocumentVerificationStatus::Valide, null, $request);
    }

    public function rejectDocument(Worker $worker, Document $document, Admin $admin, string $reason, Request $request): Document
    {
        return $this->decideDocument($worker, $document, $admin, DocumentVerificationStatus::Rejete, $reason, $request);
    }

    /**
     * Suspend un ouvrier validé : il sort du matching jusqu’à $until (réactivation automatique) ou jusqu’à sa
     * réactivation par un administrateur.
     */
    public function suspend(Worker $worker, Admin $admin, string $reason, ?CarbonInterface $until, Request $request): void
    {
        if ($worker->verification_status !== WorkerVerificationStatus::Valide) {
            throw ValidationException::withMessages(['worker' => 'Seul un ouvrier validé peut être suspendu (statut : '.$worker->verification_status->label().').']);
        }

        DB::transaction(function () use ($worker, $admin, $reason, $until): void {
            $this->changeStatus($worker, WorkerVerificationStatus::Suspendu, $reason, $admin, ['suspended_until' => $until]);
            $worker->sanctions()->create(['sanction_type' => WorkerSanctionType::Suspension, 'comment' => $reason, 'starts_at' => now(), 'ends_at' => $until, 'decided_by_admin_id' => $admin->getKey()]);
        });

        AdminAudit::record($admin, 'workers', 'worker.suspended', $request, $worker, ['verification_status' => 'valide'], ['verification_status' => 'suspendu', 'reason' => $reason, 'until' => $until?->toIso8601String()]);
    }

    /**
     * Lève la suspension : l’ouvrier redevient validé. Sans administrateur, levée automatique à la date de fin.
     */
    public function reactivate(Worker $worker, ?Admin $admin, ?Request $request = null): void
    {
        if ($worker->verification_status !== WorkerVerificationStatus::Suspendu) {
            throw ValidationException::withMessages(['worker' => 'Cet ouvrier n’est pas suspendu.']);
        }

        DB::transaction(function () use ($worker, $admin): void {
            $this->changeStatus($worker, WorkerVerificationStatus::Valide, $admin ? 'Suspension levée' : 'Fin de la suspension', $admin, ['suspended_until' => null]);
            $worker->sanctions()->where('sanction_type', WorkerSanctionType::Suspension)->whereNull('lifted_at')->update(['lifted_at' => now()]);
        });

        if ($admin && $request) {
            AdminAudit::record($admin, 'workers', 'worker.reactivated', $request, $worker, ['verification_status' => 'suspendu'], ['verification_status' => 'valide']);
        }
    }

    /**
     * Exclusion définitive : l’ouvrier sort de la plateforme ; aucune réactivation possible.
     */
    public function exclude(Worker $worker, Admin $admin, string $reason, Request $request): void
    {
        if (in_array($worker->verification_status, [WorkerVerificationStatus::Exclu, WorkerVerificationStatus::Brouillon], true)) {
            throw ValidationException::withMessages(['worker' => 'Cet ouvrier ne peut pas être exclu (statut : '.$worker->verification_status->label().').']);
        }

        $before = $worker->verification_status->value;

        DB::transaction(function () use ($worker, $admin, $reason): void {
            $this->changeStatus($worker, WorkerVerificationStatus::Exclu, $reason, $admin, ['suspended_until' => null]);
            $worker->sanctions()->where('sanction_type', WorkerSanctionType::Suspension)->whereNull('lifted_at')->update(['lifted_at' => now()]);
            $worker->sanctions()->create(['sanction_type' => WorkerSanctionType::Exclusion, 'comment' => $reason, 'starts_at' => now(), 'decided_by_admin_id' => $admin->getKey()]);
        });

        AdminAudit::record($admin, 'workers', 'worker.excluded', $request, $worker, ['verification_status' => $before], ['verification_status' => 'exclu', 'reason' => $reason]);
    }

    /**
     * Lève les suspensions arrivées à leur date de fin (commande planifiée).
     */
    public function liftExpiredSuspensions(): int
    {
        $workers = Worker::query()->where('verification_status', WorkerVerificationStatus::Suspendu)->whereNotNull('suspended_until')->where('suspended_until', '<=', now())->get();
        $workers->each(fn (Worker $worker) => $this->reactivate($worker, null));

        return $workers->count();
    }

    /**
     * Suspension ou exclusion en cours, pour la fiche.
     */
    public function currentSanction(Worker $worker): ?WorkerSanction
    {
        return $worker->sanctions()->with('decidedByAdmin')->whereNull('lifted_at')->latest('starts_at')->first();
    }

    /**
     * Pièce en vigueur du dossier de l’ouvrier.
     */
    public function document(Worker $worker, string $id): Document
    {
        return $this->documents->active($worker)->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['document_id' => 'Cette pièce n’appartient pas au dossier de l’ouvrier.']);
    }

    private function decideDocument(Worker $worker, Document $document, Admin $admin, DocumentVerificationStatus $status, ?string $reason, Request $request): Document
    {
        $before = $document->verification_status->value;

        DB::transaction(function () use ($worker, $document, $admin, $status, $reason): void {
            $document->forceFill(['verification_status' => $status, 'rejection_reason' => $reason, 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();
            $this->startControl($worker, $admin);
        });

        AdminAudit::record($admin, 'documents', $status === DocumentVerificationStatus::Valide ? 'document.approved' : 'document.rejected', $request, $document,
            ['verification_status' => $before], array_filter(['verification_status' => $status->value, 'reason' => $reason, 'worker_id' => $worker->getKey()]));

        return $document;
    }

    private function ensureReviewable(Worker $worker): void
    {
        if (! in_array($worker->verification_status, self::REVIEWABLE, true)) {
            throw ValidationException::withMessages(['worker' => 'Le dossier n’est pas en attente de vérification (statut : '.$worker->verification_status->label().').']);
        }
    }

    /**
     * Première décision sur une pièce d’un dossier transmis : le dossier passe « En contrôle », pris en charge par
     * l’administrateur.
     */
    private function startControl(Worker $worker, Admin $admin): void
    {
        $review = $worker->verificationReviews()->whereIn('status', [VerificationReviewStatus::Soumis, VerificationReviewStatus::EnControle])->latest('submission_number')->first();
        $review?->update(['status' => VerificationReviewStatus::EnControle, 'assigned_admin_id' => $review->assigned_admin_id ?? $admin->getKey()]);

        if ($worker->verification_status === WorkerVerificationStatus::Soumis) {
            $this->changeStatus($worker, WorkerVerificationStatus::EnControle, 'Prise en charge par le Back Office', $admin);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function changeStatus(Worker $worker, WorkerVerificationStatus $status, string $reason, ?Admin $admin, array $attributes = []): void
    {
        $worker->statusHistories()->create([
            'from_status' => $worker->verification_status->value,
            'to_status' => $status->value,
            'reason' => $reason,
            'actor_type' => $admin ? $admin->getMorphClass() : 'systeme',
            'actor_id' => $admin?->getKey(),
        ]);
        $worker->forceFill([...$attributes, 'verification_status' => $status, 'lock_version' => $worker->lock_version + 1])->save();
    }

    /**
     * Contrôle le plus récent du dossier.
     */
    public function latestReview(Worker $worker): ?VerificationReview
    {
        return $worker->verificationReviews()->latest('submission_number')->first();
    }
}
