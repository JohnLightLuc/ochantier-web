<?php

namespace App\Support\Admin;

use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\VerificationReviewStatus;
use App\Models\Admin;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\VerificationReview;
use App\Support\Contractor\ContractorAccount;
use App\Support\Contractor\ContractorNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur le dossier d’un entrepreneur : validation, corrections demandées, refus, suspension
 * et réactivation. Chaque décision est notifiée à l’entrepreneur et, prise par un administrateur, tracée dans le
 * journal d’audit. Sans administrateur, elle provient de la commande de démonstration (hors production).
 */
class ContractorVerification
{
    public function __construct(private ContractorAccount $account, private ContractorNotifier $notifier) {}

    public function approve(Contractor $contractor, ?Admin $admin = null, ?Request $request = null): void
    {
        $this->ensurePending($contractor);

        if (! $this->account->completion($contractor)['complete']) {
            throw ValidationException::withMessages(['contractor' => 'Le dossier est incomplet : demandez plutôt des corrections.']);
        }

        DB::transaction(function () use ($contractor, $admin): void {
            $contractor->forceFill([
                'verification_status' => ContractorVerificationStatus::Valide,
                'validated_at' => now(),
                'validated_by_admin_id' => $admin?->getKey(),
                'verification_reason' => null,
            ])->save();

            $this->account->activeDocuments($contractor)
                ->filter(fn (Document $document) => $document->verification_status === DocumentVerificationStatus::EnAttente)
                ->each(fn (Document $document) => $document->forceFill(['verification_status' => DocumentVerificationStatus::Valide, 'verified_by_admin_id' => $admin?->getKey(), 'verified_at' => now()])->save());

            $this->decide($contractor, $admin, VerificationReviewStatus::Valide);
        });

        $this->audit($admin, $request, 'contractor.approved', $contractor, ['verification_status' => 'valide']);
        $this->notifier->notify($contractor, 'account.approved', 'Votre profil est vérifié', 'Vous pouvez désormais répondre aux demandes de devis et aux offres contractuelles.');
    }

    /**
     * Demande des corrections ; les pièces désignées sont rejetées avec le même motif.
     *
     * @param  list<string>  $documentIds
     */
    public function requestCorrections(Contractor $contractor, string $reason, array $documentIds = [], ?Admin $admin = null, ?Request $request = null): void
    {
        $this->ensurePending($contractor);

        $documents = collect($documentIds)->map(fn (string $id) => $this->account->findDocument($contractor, $id)
            ?? throw ValidationException::withMessages(['document_ids' => 'Cette pièce n’appartient pas au dossier.']));

        DB::transaction(function () use ($contractor, $reason, $documents, $admin): void {
            $review = $this->currentReview($contractor);

            foreach ($documents as $document) {
                $document->forceFill(['verification_status' => DocumentVerificationStatus::Rejete, 'rejection_reason' => $reason, 'verified_by_admin_id' => $admin?->getKey(), 'verified_at' => now()])->save();
                $review->verificationCorrections()->create(['document_id' => $document->getKey(), 'reason' => $reason]);
            }

            $contractor->forceFill(['verification_status' => ContractorVerificationStatus::ACorriger, 'verification_reason' => $reason])->save();
            $this->decide($contractor, $admin, VerificationReviewStatus::ACorriger, $reason);
        });

        $this->audit($admin, $request, 'contractor.corrections_requested', $contractor, ['verification_status' => 'a_corriger', 'reason' => $reason, 'document_ids' => $documentIds]);
        $this->notifier->notify($contractor, 'account.corrections', 'Des corrections sont demandées', $reason);
    }

    public function reject(Contractor $contractor, string $reason, ?Admin $admin = null, ?Request $request = null): void
    {
        $this->ensurePending($contractor);

        DB::transaction(function () use ($contractor, $reason, $admin): void {
            $contractor->forceFill(['verification_status' => ContractorVerificationStatus::Refuse, 'validated_at' => null, 'verification_reason' => $reason])->save();
            $this->decide($contractor, $admin, VerificationReviewStatus::Refuse, $reason);
        });

        $this->audit($admin, $request, 'contractor.rejected', $contractor, ['verification_status' => 'refuse', 'reason' => $reason]);
        $this->notifier->notify($contractor, 'account.rejected', 'Dossier rejeté', 'Motif : '.$reason);
    }

    /**
     * Suspend le compte : l’entrepreneur garde l’accès en consultation, sans pouvoir répondre ni modifier son dossier.
     */
    public function suspend(Contractor $contractor, string $reason, ?Admin $admin = null, ?Request $request = null): void
    {
        if ($contractor->status !== ContractorStatus::Actif) {
            throw ValidationException::withMessages(['contractor' => 'Ce compte est déjà suspendu.']);
        }

        $contractor->forceFill(['status' => ContractorStatus::Suspendu, 'suspended_reason' => $reason])->save();

        $this->audit($admin, $request, 'contractor.suspended', $contractor, ['status' => 'suspendu', 'reason' => $reason]);
        $this->notifier->notify($contractor, 'account.suspended', 'Compte suspendu', $reason.' Contactez l’assistance O’CHANTIER.');
    }

    public function reactivate(Contractor $contractor, ?Admin $admin = null, ?Request $request = null): void
    {
        if ($contractor->status !== ContractorStatus::Suspendu) {
            throw ValidationException::withMessages(['contractor' => 'Ce compte n’est pas suspendu.']);
        }

        $contractor->forceFill(['status' => ContractorStatus::Actif, 'suspended_reason' => null])->save();

        $this->audit($admin, $request, 'contractor.reactivated', $contractor, ['status' => 'actif']);
        $this->notifier->notify($contractor, 'account.reactivated', 'Compte réactivé', 'Vous pouvez de nouveau utiliser O’CHANTIER.');
    }

    private function ensurePending(Contractor $contractor): void
    {
        if (! $contractor->verification_status->isPending()) {
            throw ValidationException::withMessages(['contractor' => 'Le dossier n’est pas en attente de vérification (statut : '.$contractor->verification_status->label().').']);
        }
    }

    private function currentReview(Contractor $contractor): VerificationReview
    {
        return $contractor->verificationReviews()
            ->whereIn('status', [VerificationReviewStatus::Soumis, VerificationReviewStatus::EnControle])
            ->latest('submission_number')
            ->firstOrFail();
    }

    private function decide(Contractor $contractor, ?Admin $admin, VerificationReviewStatus $status, ?string $reason = null): void
    {
        $this->currentReview($contractor)->update([
            'status' => $status,
            'assigned_admin_id' => $admin?->getKey(),
            'decision_reason' => $reason,
            'decided_by_admin_id' => $admin?->getKey(),
            'decided_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(?Admin $admin, ?Request $request, string $action, Contractor $contractor, array $after): void
    {
        if ($admin && $request) {
            AdminAudit::record($admin, 'contractors', $action, $request, $contractor, null, $after);
        }
    }
}
