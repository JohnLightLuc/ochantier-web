<?php

namespace App\Support\Admin;

use App\Enums\ClaimStatus;
use App\Enums\DisputePartyRole;
use App\Enums\DisputeStatus;
use App\Enums\Priority;
use App\Models\Admin;
use App\Models\Claim;
use App\Models\CommercialProfile;
use App\Models\Dispute;
use App\Models\Mission;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\ClaimFiling;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Media\MediaStore;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Procédure d’un litige : ouvert, en instruction, résolu par une décision motivée, en recours, clos. Tant qu’il est
 * ouvert, il bloque les paiements de la mission ou de l’affectation concernée.
 *
 * Le responsable du dossier ne décide pas seul : la décision revient à une autre personne (disputes.validate), et le
 * recours à une personne qui n’a pas rendu la première décision (disputes.approve). Le litige ne touche aucun solde :
 * un remboursement passe par Remboursements et sa double validation.
 */
class DisputeOperations
{
    public const APPEAL_DAYS = 15;

    public function __construct(
        private Refunds $refunds,
        private MediaStore $media,
        private PromoterNotifier $promoters,
        private CommercialNotifier $commercials,
    ) {}

    /**
     * @return array{take: bool, urgency: bool, link_mission: bool, message: bool, proof: bool, decide: bool, decide_appeal: bool, appeal: bool, close: bool, request_refund: bool}
     */
    public function actions(Dispute $dispute): array
    {
        $open = in_array($dispute->status, [DisputeStatus::Ouvert, DisputeStatus::EnInstruction], true);
        $instructing = $dispute->status === DisputeStatus::EnInstruction;
        $appealable = $dispute->status === DisputeStatus::Resolu && $dispute->appeal_of_dispute_id === null;

        return [
            'take' => $open,
            'urgency' => $open,
            'link_mission' => $open && $dispute->appeal_of_dispute_id === null,
            'message' => $dispute->status !== DisputeStatus::Clos,
            'proof' => $dispute->status !== DisputeStatus::Clos,
            'decide' => $instructing && $dispute->appeal_of_dispute_id === null,
            'decide_appeal' => $instructing && $dispute->appeal_of_dispute_id !== null,
            'appeal' => $appealable && $dispute->appeal_deadline?->isFuture() === true,
            'close' => $appealable && $dispute->appeal_deadline?->isPast() === true,
            'request_refund' => ($dispute->financial_impact['refund_pending'] ?? false) === true && $dispute->status !== DisputeStatus::EnRecours && $dispute->mission !== null && $this->refunds->isSettled($dispute->mission),
        ];
    }

    /**
     * @param  array{category: string, description: string, urgency: string}  $data
     */
    public function open(Model $claimant, array $data, ?Mission $mission, Admin $admin, Request $request): Dispute
    {
        return DB::transaction(function () use ($claimant, $data, $mission, $admin, $request): Dispute {
            $dispute = $this->create($claimant, app(ClaimFiling::class)->otherParty($claimant, null, $mission?->site), $data, $mission?->site_id, $mission?->getKey(), null, null, $admin);
            AdminAudit::record($admin, 'disputes', 'disputes.opened', $request, $dispute, null, ['status' => $dispute->status->value]);

            return $dispute;
        });
    }

    /**
     * Une réclamation devient un litige : le dossier reprend sa demande, ses parties et son chantier.
     */
    public function fromClaim(Claim $claim, ?Mission $mission, Priority $urgency, Admin $admin, Request $request): Dispute
    {
        return DB::transaction(function () use ($claim, $mission, $urgency, $admin, $request): Dispute {
            $claim = Claim::query()->lockForUpdate()->findOrFail($claim->getKey());

            if (! in_array($claim->status, ClaimFiling::OPEN, true)) {
                throw ValidationException::withMessages(['claim' => 'Cette réclamation n’est plus à traiter.']);
            }

            $missionId = $claim->mission_id ?? $mission?->getKey();

            if ($mission && $claim->site_id && $mission->site_id !== $claim->site_id) {
                throw ValidationException::withMessages(['mission_reference' => 'Cette mission n’est pas sur le chantier de la réclamation.']);
            }

            $dispute = $this->create($claim->claimant, $claim->otherParty, ['category' => $claim->category, 'description' => $claim->subject."\n\n".$claim->description, 'urgency' => $urgency->value], $claim->site_id ?? $mission?->site_id, $missionId, $claim->assignment_id, $claim->rating_id, $admin);
            $claim->update(['status' => ClaimStatus::Escaladee, 'dispute_id' => $dispute->getKey()]);
            AdminAudit::record($admin, 'disputes', 'claims.escalated', $request, $claim, null, ['status' => ClaimStatus::Escaladee->value, 'reason' => $dispute->reference]);
            AdminAudit::record($admin, 'disputes', 'disputes.opened', $request, $dispute, null, ['status' => $dispute->status->value, 'reason' => 'Depuis '.$claim->reference]);

            return $dispute;
        });
    }

    public function take(Dispute $dispute, Admin $admin, Request $request): void
    {
        $this->change($dispute, 'take', 'disputes.taken', $admin, $request, null, ['status' => DisputeStatus::EnInstruction, 'assigned_admin_id' => $admin->getKey()]);
    }

    public function setUrgency(Dispute $dispute, Priority $urgency, Admin $admin, Request $request): void
    {
        $this->change($dispute, 'urgency', 'disputes.urgency', $admin, $request, $urgency->label(), ['urgency' => $urgency]);
    }

    public function linkMission(Dispute $dispute, Mission $mission, Admin $admin, Request $request): void
    {
        if ($dispute->site_id && $mission->site_id !== $dispute->site_id) {
            throw ValidationException::withMessages(['mission_reference' => 'Cette mission n’est pas sur le chantier du litige.']);
        }

        $this->change($dispute, 'link_mission', 'disputes.mission_linked', $admin, $request, $mission->reference, ['mission_id' => $mission->getKey(), 'site_id' => $mission->site_id]);
    }

    public function message(Dispute $dispute, string $body, bool $internal, Admin|User $author, ?Request $request = null): void
    {
        $this->ensure($this->actions($dispute)['message'], 'Ce litige est clos.');

        $dispute->messages()->create(['author_type' => $author->getMorphClass(), 'author_id' => $author->getKey(), 'body' => $body, 'is_internal' => $internal]);

        if ($author instanceof Admin && ! $internal) {
            $this->notifyParties($dispute, 'Nouveau message sur votre litige', 'L’équipe O’Chantier a écrit sur le litige '.$dispute->reference.'.');
        }
    }

    public function addProof(Dispute $dispute, UploadedFile $file, ?string $label, Admin|User $author): void
    {
        $this->ensure($this->actions($dispute)['proof'], 'Ce litige est clos.');

        $dispute->attachments()->create([
            'media_file_id' => $this->media->store($file, 'dispute_evidence', $author)->getKey(),
            'label' => $label ?: $file->getClientOriginalName(),
            'created_by_type' => $author->getMorphClass(),
            'created_by_id' => $author->getKey(),
        ]);
    }

    /**
     * @param  array{decision: string, refund_amount?: int|null, financial_note?: string|null}  $data
     */
    public function decide(Dispute $dispute, array $data, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($dispute, $data, $admin, $request): void {
            $locked = $this->lock($dispute);
            $this->ensure($this->actions($locked)['decide'], 'Ce litige n’est pas en instruction.');
            $this->ensure($locked->assigned_admin_id !== $admin->getKey(), 'Le responsable du dossier ne peut pas rendre la décision.');

            $this->settle($locked, $data, $admin, $request, DisputeStatus::Resolu, ['appeal_deadline' => now()->addDays(self::APPEAL_DAYS)]);
            AdminAudit::record($admin, 'disputes', 'disputes.decided', $request, $locked, null, ['status' => $locked->status->value, 'reason' => $data['decision'], 'refund_amount' => $data['refund_amount'] ?? null]);
        });

        $this->notifyParties($dispute, 'Décision rendue sur votre litige', 'Décision sur le litige '.$dispute->reference.' : '.$data['decision'].' Recours possible sous '.self::APPEAL_DAYS.' jours.');
    }

    /**
     * Le recours ouvre un nouveau dossier lié au premier, qui reste bloquant jusqu’à la décision.
     */
    public function appeal(Dispute $dispute, string $reason, Admin|User $author, ?Request $request = null): Dispute
    {
        $appeal = DB::transaction(function () use ($dispute, $reason, $author, $request): Dispute {
            $locked = $this->lock($dispute);
            $this->ensure($this->actions($locked)['appeal'], 'Le délai de recours est dépassé ou ce litige n’est pas résolu.');

            $appeal = $locked->replicate(['id', 'reference', 'decision', 'financial_impact', 'decided_by_admin_id', 'decided_at', 'appeal_deadline', 'assigned_admin_id']);
            $appeal->forceFill([
                'reference' => $this->reference(),
                'description' => $reason,
                'status' => DisputeStatus::Ouvert,
                'appeal_of_dispute_id' => $locked->getKey(),
                'opened_by_type' => $author->getMorphClass(),
                'opened_by_id' => $author->getKey(),
            ])->save();
            $locked->parties->each(fn ($party) => $appeal->parties()->create($party->only(['party_type', 'party_id', 'role'])));
            $locked->update(['status' => DisputeStatus::EnRecours]);

            if ($author instanceof Admin && $request) {
                AdminAudit::record($author, 'disputes', 'disputes.appealed', $request, $locked, null, ['status' => DisputeStatus::EnRecours->value, 'reason' => $reason]);
            }

            return $appeal;
        });

        $this->notifyParties($dispute, 'Recours ouvert', 'Un recours est ouvert sur le litige '.$dispute->reference.'.');

        return $appeal;
    }

    /**
     * Décision définitive : elle confirme ou annule la première, et clôt les deux dossiers.
     *
     * @param  array{outcome: string, decision: string, refund_amount?: int|null, financial_note?: string|null}  $data
     */
    public function decideAppeal(Dispute $appeal, array $data, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($appeal, $data, $admin, $request): void {
            $locked = $this->lock($appeal);
            $this->ensure($this->actions($locked)['decide_appeal'], 'Ce recours n’est pas en instruction.');
            $this->ensure($locked->assigned_admin_id !== $admin->getKey(), 'Le responsable du dossier ne peut pas rendre la décision.');

            $original = $this->lock($locked->appealOfDispute);
            $this->ensure($original->decided_by_admin_id !== $admin->getKey(), 'Le recours doit être examiné par une autre personne que celle qui a décidé.');

            $this->settle($locked, $data, $admin, $request, DisputeStatus::Clos, [], ['outcome' => $data['outcome']]);
            $original->update(['status' => DisputeStatus::Clos]);
            AdminAudit::record($admin, 'disputes', 'disputes.appeal_decided', $request, $locked, null, ['status' => $locked->status->value, 'reason' => $data['decision'], 'outcome' => $data['outcome'], 'refund_amount' => $data['refund_amount'] ?? null]);
        });

        $this->notifyParties($appeal, 'Décision sur votre recours', 'Recours '.$appeal->reference.' : '.$data['decision']);
    }

    public function close(Dispute $dispute, Admin $admin, Request $request): void
    {
        $this->change($dispute, 'close', 'disputes.closed', $admin, $request, null, ['status' => DisputeStatus::Clos]);
    }

    /**
     * Crée la demande de remboursement restée en attente, une fois la mission régularisée.
     */
    public function requestRefund(Dispute $dispute, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($dispute, $admin, $request): void {
            $locked = $this->lock($dispute);
            $this->ensure($this->actions($locked)['request_refund'], 'Aucun remboursement n’est à demander : la mission doit d’abord être régularisée.');

            $refund = $this->refunds->fromDispute($locked, (int) $locked->financial_impact['refund_amount'], $admin, $request);
            $locked->update(['financial_impact' => [...$locked->financial_impact, 'refund_pending' => false, 'refund_id' => $refund->getKey()]]);
        });
    }

    /**
     * @param  array{decision: string, refund_amount?: int|null, financial_note?: string|null}  $data
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $impact
     */
    private function settle(Dispute $dispute, array $data, Admin $admin, Request $request, DisputeStatus $status, array $values = [], array $impact = []): void
    {
        $amount = $data['refund_amount'] ?? null;
        $this->ensure(! $amount || $dispute->mission_id !== null, 'Rattachez d’abord ce litige à une mission : le remboursement en dépend.');

        $impact = [...$impact, 'refund_amount' => $amount, 'note' => $data['financial_note'] ?? null, 'refund_pending' => false];

        // La mission non régularisée refacturerait le remboursement : le montant attend le calcul de fin de mission.
        if ($amount && $this->refunds->isSettled($dispute->mission)) {
            $impact['refund_id'] = $this->refunds->fromDispute($dispute, $amount, $admin, $request)->getKey();
        } elseif ($amount) {
            $impact['refund_pending'] = true;
        }

        $dispute->update([...$values, 'status' => $status, 'decision' => $data['decision'], 'financial_impact' => $impact, 'decided_by_admin_id' => $admin->getKey(), 'decided_at' => now()]);
    }

    /**
     * @param  array{category: string, description: string, urgency: string}  $data
     */
    private function create(Model $claimant, ?Model $respondent, array $data, ?string $siteId, ?string $missionId, ?string $assignmentId, ?string $ratingId, Admin $admin): Dispute
    {
        $dispute = Dispute::query()->create([
            'reference' => $this->reference(),
            'category' => $data['category'],
            'opened_by_type' => $admin->getMorphClass(),
            'opened_by_id' => $admin->getKey(),
            'site_id' => $siteId,
            'mission_id' => $missionId,
            'assignment_id' => $assignmentId,
            'rating_id' => $ratingId,
            'description' => $data['description'],
            'urgency' => $data['urgency'],
            'status' => DisputeStatus::Ouvert,
        ]);
        $dispute->parties()->create(['party_type' => $claimant->getMorphClass(), 'party_id' => $claimant->getKey(), 'role' => DisputePartyRole::Demandeur]);

        if ($respondent) {
            $dispute->parties()->create(['party_type' => $respondent->getMorphClass(), 'party_id' => $respondent->getKey(), 'role' => DisputePartyRole::Defendeur]);
        }

        $this->notifyParties($dispute, 'Litige ouvert', 'Le litige '.$dispute->reference.' est ouvert et vous concerne.');

        return $dispute;
    }

    private function notifyParties(Dispute $dispute, string $title, string $body): void
    {
        $dispute->parties()->get()->each(function ($row) use ($dispute, $title, $body): void {
            $party = $row->party;

            match (true) {
                $party instanceof PromoterProfile => $this->promoters->disputeUpdated($party, $dispute, $title, $body),
                $party instanceof CommercialProfile => $this->commercials->accountEvent($party, 'dispute.updated', $title, $body),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function change(Dispute $dispute, string $action, string $event, Admin $admin, Request $request, ?string $reason, array $values): void
    {
        DB::transaction(function () use ($dispute, $action, $event, $admin, $request, $reason, $values): void {
            $locked = $this->lock($dispute);
            $this->ensure($this->actions($locked)[$action], 'Cette action n’est plus possible sur ce litige.');

            $locked->update($values);
            AdminAudit::record($admin, 'disputes', $event, $request, $locked, null, ['status' => $locked->status->value, 'reason' => $reason]);
        });
    }

    private function lock(Dispute $dispute): Dispute
    {
        return Dispute::query()->with(['mission', 'appealOfDispute', 'parties'])->lockForUpdate()->findOrFail($dispute->getKey());
    }

    private function reference(): string
    {
        return sprintf('LIT-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('dispute_reference_seq')"));
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['dispute' => $message]);
        }
    }
}
