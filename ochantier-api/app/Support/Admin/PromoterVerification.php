<?php

namespace App\Support\Admin;

use App\Enums\DocumentVerificationStatus;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Enums\VerificationReviewStatus;
use App\Models\Admin;
use App\Models\Document;
use App\Models\PromoterProfile;
use App\Models\VerificationReview;
use App\Support\Auth\AuthSessions;
use App\Support\Promoter\PromoterAccount;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle des comptes promoteurs par le Back Office : vérification des pièces, décision sur le dossier
 * (validation, corrections, refus), suspension et messages.
 *
 * Le dossier passe de « Soumis » à « En contrôle » dès la première décision sur une pièce. Il ne peut être validé
 * que complet et avec toutes ses pièces obligatoires vérifiées. Une demande de corrections rejette les pièces
 * concernées : le promoteur les remplace depuis l’application, et le dossier revient automatiquement en
 * vérification (PromoterAccount::refreshVerification). La suspension bloque la connexion sans toucher au statut
 * de vérification, rétabli tel quel à la réactivation.
 */
class PromoterVerification
{
    /**
     * Statuts dans lesquels le dossier attend une décision du Back Office.
     */
    public const REVIEWABLE = [PromoterVerificationStatus::Soumis, PromoterVerificationStatus::EnControle];

    public function __construct(private PromoterAccount $account, private PromoterNotifier $notifier, private AuthSessions $sessions) {}

    /**
     * Pièces obligatoires pour la validation : pièce d’identité, certificat de résidence et, pour une entreprise,
     * extrait RCCM et attestation NCC. Clé => [libellé, pièce en vigueur ou null].
     *
     * @return array<string, array{0: string, 1: Document|null}>
     */
    public function requiredDocuments(PromoterProfile $profile): array
    {
        $documents = $this->account->activeDocuments($profile);
        $of = fn (string $code) => $documents->first(fn (Document $document) => $document->documentType->code === $code);
        $required = [
            'identity' => ['Pièce d’identité', $documents->first(fn (Document $document) => $document->documentType->is_identity)],
            'residence' => ['Certificat de résidence', $of(PromoterAccount::RESIDENCE_DOCUMENT)],
        ];

        if ($this->account->isCompany($profile)) {
            $required['organization_rccm'] = ['Extrait RCCM', $of('organization_rccm')];
            $required['organization_dfe'] = ['Attestation NCC / DFE', $of('organization_dfe')];
        }

        return $required;
    }

    /**
     * Motif pour lequel le dossier ne peut pas être validé, ou null.
     */
    public function approvalRefusal(PromoterProfile $profile): ?string
    {
        if ($profile->user->status === UserStatus::Suspendu) {
            return 'Le compte est suspendu : réactivez-le avant de le valider.';
        }

        if (! in_array($profile->verification_status, self::REVIEWABLE, true)) {
            return 'Le dossier n’est pas en attente de vérification (statut : '.$profile->verification_status->label().').';
        }

        if (! $this->account->completion($profile)['complete']) {
            return 'Le dossier est incomplet.';
        }

        $unverified = array_keys(array_filter($this->requiredDocuments($profile), fn (array $item) => $item[1]?->verification_status !== DocumentVerificationStatus::Valide));

        if ($unverified !== []) {
            $labels = array_map(fn (string $key) => $this->requiredDocuments($profile)[$key][0], $unverified);

            return 'Pièces à vérifier avant la validation : '.implode(', ', $labels).'.';
        }

        return null;
    }

    public function approve(PromoterProfile $profile, Admin $admin, Request $request): void
    {
        if ($refusal = $this->approvalRefusal($profile)) {
            throw ValidationException::withMessages(['promoter' => $refusal]);
        }

        $before = $profile->verification_status->value;

        DB::transaction(function () use ($profile, $admin): void {
            $profile->forceFill(['verification_status' => PromoterVerificationStatus::Valide, 'validated_at' => now(), 'validated_by_admin_id' => $admin->getKey()])->save();
            $this->account->organization($profile)?->forceFill(['verification_status' => PromoterVerificationStatus::Valide, 'validated_at' => now()])->save();
            $this->decide($profile, $admin, VerificationReviewStatus::Valide);
        });

        AdminAudit::record($admin, 'promoters', 'promoter.approved', $request, $profile, ['verification_status' => $before], ['verification_status' => 'valide']);
        $this->notifier->accountEvent($profile, 'account.approved', 'Votre compte est vérifié', 'Votre identité a été vérifiée : vous pouvez publier vos chantiers sans restriction.');
    }

    /**
     * Demande des corrections : chaque correction porte sur une pièce (rejetée avec le motif) ou sur un élément
     * du profil.
     *
     * @param  list<array{document_id?: string|null, field?: string|null, reason: string}>  $corrections
     */
    public function requestCorrections(PromoterProfile $profile, Admin $admin, array $corrections, ?string $message, Request $request): void
    {
        $this->ensureReviewable($profile);
        $before = $profile->verification_status->value;

        DB::transaction(function () use ($profile, $admin, $corrections, $message): void {
            $review = $this->currentReview($profile, $admin);

            foreach ($corrections as $correction) {
                $document = isset($correction['document_id']) ? $this->document($profile, $correction['document_id']) : null;
                $document?->forceFill(['verification_status' => DocumentVerificationStatus::Rejete, 'rejection_reason' => $correction['reason'], 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();

                $review->verificationCorrections()->create([
                    'document_id' => $document?->getKey(),
                    'target_field' => $document ? null : ($correction['field'] ?? null),
                    'reason' => $correction['reason'],
                ]);
            }

            $profile->forceFill(['verification_status' => PromoterVerificationStatus::ACorriger])->save();
            $this->account->organization($profile)?->forceFill(['verification_status' => PromoterVerificationStatus::ACorriger])->save();
            $this->decide($profile, $admin, VerificationReviewStatus::ACorriger, $message);
        });

        AdminAudit::record($admin, 'promoters', 'promoter.corrections_requested', $request, $profile, ['verification_status' => $before], ['verification_status' => 'a_corriger', 'corrections' => $corrections]);
        $this->notifier->accountEvent($profile, 'account.corrections', 'Des corrections sont demandées', $message ?: 'Certaines pièces de votre profil doivent être corrigées. Ouvrez votre profil pour les remplacer.');
    }

    public function reject(PromoterProfile $profile, Admin $admin, string $reason, Request $request): void
    {
        $this->ensureReviewable($profile);
        $before = $profile->verification_status->value;

        DB::transaction(function () use ($profile, $admin, $reason): void {
            $profile->forceFill(['verification_status' => PromoterVerificationStatus::Refuse, 'validated_at' => null])->save();
            $this->account->organization($profile)?->forceFill(['verification_status' => PromoterVerificationStatus::Refuse, 'validated_at' => null])->save();
            $this->decide($profile, $admin, VerificationReviewStatus::Refuse, $reason);
        });

        AdminAudit::record($admin, 'promoters', 'promoter.rejected', $request, $profile, ['verification_status' => $before], ['verification_status' => 'refuse', 'reason' => $reason]);
        $this->notifier->accountEvent($profile, 'account.rejected', 'Vérification refusée', $reason);
    }

    public function approveDocument(PromoterProfile $profile, Document $document, Admin $admin, Request $request): Document
    {
        $before = $document->verification_status->value;

        DB::transaction(function () use ($profile, $document, $admin): void {
            $document->forceFill(['verification_status' => DocumentVerificationStatus::Valide, 'rejection_reason' => null, 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();
            $this->startControl($profile, $admin);
        });

        AdminAudit::record($admin, 'documents', 'document.approved', $request, $document, ['verification_status' => $before], ['verification_status' => 'valide']);

        return $document;
    }

    public function rejectDocument(PromoterProfile $profile, Document $document, Admin $admin, string $reason, Request $request): Document
    {
        $before = $document->verification_status->value;

        DB::transaction(function () use ($profile, $document, $admin, $reason): void {
            $document->forceFill(['verification_status' => DocumentVerificationStatus::Rejete, 'rejection_reason' => $reason, 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();
            $this->startControl($profile, $admin);
        });

        AdminAudit::record($admin, 'documents', 'document.rejected', $request, $document, ['verification_status' => $before], ['verification_status' => 'rejete', 'reason' => $reason]);

        return $document;
    }

    /**
     * Suspend le compte : connexion bloquée et sessions fermées, statut de vérification conservé.
     */
    public function suspend(PromoterProfile $profile, Admin $admin, string $reason, Request $request): void
    {
        $user = $profile->user;

        if ($user->status === UserStatus::Suspendu) {
            throw ValidationException::withMessages(['promoter' => 'Ce compte est déjà suspendu.']);
        }

        $before = $user->status->value;

        DB::transaction(function () use ($user, $admin, $reason): void {
            $user->forceFill(['status' => UserStatus::Suspendu, 'suspended_at' => now(), 'suspended_by_admin_id' => $admin->getKey(), 'suspended_reason' => $reason])->save();
            $this->sessions->revokeAll($user, 'suspended_by_admin');
        });

        AdminAudit::record($admin, 'promoters', 'promoter.suspended', $request, $profile, ['status' => $before], ['status' => 'suspendu', 'reason' => $reason]);
        $this->notifier->accountEvent($profile, 'account.suspended', 'Votre compte est suspendu', $reason.' Contactez le support O’CHANTIER.');
    }

    public function reactivate(PromoterProfile $profile, Admin $admin, Request $request): void
    {
        $user = $profile->user;

        if ($user->status !== UserStatus::Suspendu) {
            throw ValidationException::withMessages(['promoter' => 'Ce compte n’est pas suspendu.']);
        }

        $user->forceFill(['status' => UserStatus::Actif, 'suspended_at' => null, 'suspended_by_admin_id' => null, 'suspended_reason' => null])->save();

        AdminAudit::record($admin, 'promoters', 'promoter.reactivated', $request, $profile, ['status' => 'suspendu'], ['status' => 'actif']);
        $this->notifier->accountEvent($profile, 'account.reactivated', 'Votre compte est réactivé', 'Vous pouvez de nouveau utiliser O’CHANTIER.');
    }

    /**
     * Message libre de l’équipe O’CHANTIER au promoteur (centre de notifications et push).
     */
    public function message(PromoterProfile $profile, Admin $admin, string $title, string $body, Request $request): void
    {
        $this->notifier->accountEvent($profile, 'admin.message', $title, $body);
        AdminAudit::record($admin, 'promoters', 'promoter.messaged', $request, $profile, null, ['title' => $title]);
    }

    /**
     * Pièce en vigueur du promoteur ou de son entreprise.
     */
    public function document(PromoterProfile $profile, string $id): Document
    {
        return $this->account->findDocument($profile, $id)
            ?? throw ValidationException::withMessages(['document_id' => 'Cette pièce n’appartient pas au dossier du promoteur.']);
    }

    private function ensureReviewable(PromoterProfile $profile): void
    {
        if (! in_array($profile->verification_status, self::REVIEWABLE, true)) {
            throw ValidationException::withMessages(['promoter' => 'Le dossier n’est pas en attente de vérification (statut : '.$profile->verification_status->label().').']);
        }
    }

    /**
     * Première décision sur une pièce d’un dossier soumis : le dossier passe « En contrôle », pris en charge par
     * l’administrateur.
     */
    private function startControl(PromoterProfile $profile, Admin $admin): void
    {
        if ($profile->verification_status !== PromoterVerificationStatus::Soumis) {
            return;
        }

        $profile->forceFill(['verification_status' => PromoterVerificationStatus::EnControle])->save();
        $this->account->organization($profile)?->forceFill(['verification_status' => PromoterVerificationStatus::EnControle])->save();
        $this->currentReview($profile, $admin)->update(['status' => VerificationReviewStatus::EnControle]);
    }

    /**
     * Demande de vérification en cours (créée si le dossier a été soumis avant le suivi des demandes).
     */
    private function currentReview(PromoterProfile $profile, Admin $admin): VerificationReview
    {
        $review = $profile->verificationReviews()
            ->whereIn('status', [VerificationReviewStatus::Soumis, VerificationReviewStatus::EnControle])
            ->latest('submission_number')
            ->first();

        $review ??= $profile->verificationReviews()->create([
            'submission_number' => $profile->verificationReviews()->max('submission_number') + 1,
            'submitted_by_type' => $profile->user->getMorphClass(),
            'submitted_by_id' => $profile->user_id,
            'submitted_at' => now(),
            'status' => VerificationReviewStatus::Soumis,
        ]);

        if (! $review->assigned_admin_id) {
            $review->update(['assigned_admin_id' => $admin->getKey()]);
        }

        return $review;
    }

    private function decide(PromoterProfile $profile, Admin $admin, VerificationReviewStatus $status, ?string $reason = null): void
    {
        $this->currentReview($profile, $admin)->update([
            'status' => $status,
            'decision_reason' => $reason,
            'decided_by_admin_id' => $admin->getKey(),
            'decided_at' => now(),
        ]);
    }
}
