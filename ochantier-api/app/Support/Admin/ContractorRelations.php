<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Document;
use App\Models\QuoteBid;
use App\Models\Site;
use App\Models\VerificationReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onglets en lecture seule de la fiche d’un entrepreneur dans le Back Office : réponses aux opportunités (demandes de
 * devis et offres contractuelles) et historique du dossier.
 */
class ContractorRelations
{
    /**
     * Nombre maximal de lignes renvoyées par onglet (les plus récentes).
     */
    public const LIMIT = 100;

    /**
     * Libellés des décisions du Back Office tracées dans le journal d’audit.
     */
    private const ADMIN_LABELS = [
        'contractor.approved' => 'a vérifié le profil',
        'contractor.corrections_requested' => 'a demandé des corrections',
        'contractor.rejected' => 'a rejeté le dossier',
        'contractor.suspended' => 'a suspendu le compte',
        'contractor.reactivated' => 'a réactivé le compte',
        'document.approved' => 'a confirmé une pièce',
        'document.rejected' => 'a rejeté une pièce',
    ];

    /**
     * Propositions de visite (demandes de devis) et réponses aux offres contractuelles, de la plus récente à la plus
     * ancienne. Les brouillons de réponse restent privés à l’entrepreneur.
     *
     * @return list<array<string, mixed>>
     */
    public function responses(Contractor $contractor): array
    {
        $quoteBids = QuoteBid::query()
            ->where('contractor_id', $contractor->getKey())
            ->with(['quoteRequestTrade.trade', 'quoteRequestTrade.quoteRequest.site.promoterProfile.user'])
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (QuoteBid $bid): array {
                $trade = $bid->quoteRequestTrade;
                $request = $trade?->quoteRequest;

                return [
                    'id' => $bid->id,
                    'kind' => ['value' => 'demande_devis', 'label' => 'Demande de devis'],
                    'opportunity' => ['id' => $request?->id, 'reference' => $request?->reference],
                    'title' => trim(($trade?->trade?->label ?? 'Métier').' · '.($request?->site?->label ?? 'Chantier')),
                    'promoter' => $this->promoterName($request?->site),
                    'amount' => $bid->fee_amount !== null ? (int) $bid->fee_amount : null,
                    'amount_label' => 'Frais de visite',
                    'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
                    'at' => $bid->created_at?->toIso8601String(),
                ];
            });

        $offerBids = ContractorBid::query()
            ->where('contractor_id', $contractor->getKey())
            ->where('status', '!=', 'brouillon')
            ->with(['offer.site.promoterProfile.user'])
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ContractorBid $bid): array => [
                'id' => $bid->id,
                'kind' => ['value' => 'offre_contractuelle', 'label' => 'Offre contractuelle'],
                'opportunity' => ['id' => $bid->offer?->id, 'reference' => $bid->offer?->reference],
                'title' => $bid->offer?->title ?? 'Offre contractuelle',
                'promoter' => $this->promoterName($bid->offer?->site),
                'amount' => $bid->amount !== null ? (int) $bid->amount : null,
                'amount_label' => 'Prix proposé',
                'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
                'at' => ($bid->sent_at ?? $bid->created_at)?->toIso8601String(),
            ]);

        return $quoteBids->concat($offerBids)->sortByDesc('at')->take(self::LIMIT)->values()->all();
    }

    /**
     * Soumissions du dossier et décisions du Back Office (sur le dossier et ses pièces), de la plus récente à la plus
     * ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Contractor $contractor): array
    {
        $documents = Document::query()->where('owner_type', $contractor->getMorphClass())->where('owner_id', $contractor->getKey())->select('id');

        $audits = AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('subject_type', $contractor->getMorphClass())->where('subject_id', $contractor->getKey()))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', (new Document)->getMorphClass())->whereIn('subject_id', $documents)))
            ->with(['actor', 'subject'])
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (AuditLog $log): array {
                $values = $log->after_values ?? [];
                $actor = $log->actor;
                $document = $log->subject instanceof Document ? $log->subject->documentType?->label : null;

                return [
                    'id' => $log->id,
                    'kind' => ['value' => 'admin', 'label' => 'Administrateur'],
                    'author' => $actor instanceof Admin ? $actor->fullName() : 'Administrateur supprimé',
                    'what' => self::ADMIN_LABELS[$log->action] ?? $log->action,
                    'detail' => collect([$document, $values['reason'] ?? null])->filter()->implode(' · ') ?: null,
                    'at' => CarbonImmutable::parse($log->created_at)->toIso8601String(),
                ];
            });

        $submissions = $contractor->verificationReviews()
            ->whereNotNull('submitted_at')
            ->latest('submission_number')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (VerificationReview $review): array => [
                'id' => $review->id,
                'kind' => ['value' => 'contractor', 'label' => 'Entrepreneur'],
                'author' => $contractor->name ?: 'Entrepreneur',
                'what' => $review->submission_number > 1 ? 'a soumis de nouveau son dossier' : 'a soumis son dossier',
                'detail' => 'Soumission n° '.$review->submission_number,
                'at' => $review->submitted_at->toIso8601String(),
            ]);

        return $audits->concat($submissions)->sortByDesc('at')->take(self::LIMIT)->values()->all();
    }

    private function promoterName(?Site $site): ?string
    {
        $user = $site?->promoterProfile?->user;

        return $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null;
    }
}
