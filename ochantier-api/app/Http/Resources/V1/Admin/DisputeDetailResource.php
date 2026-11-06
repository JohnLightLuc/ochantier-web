<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Admin;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Refund;
use App\Models\User;
use App\Support\Admin\DisputeOperations;
use App\Support\Media\MediaStore;
use Illuminate\Http\Request;

/**
 * Fiche d’un litige : parties, échanges, preuves, décision, recours, journal et actions possibles.
 *
 * @mixin Dispute
 */
class DisputeDetailResource extends DisputeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaStore::class);
        $impact = $this->financial_impact ?? [];
        $refund = isset($impact['refund_id']) ? Refund::query()->find($impact['refund_id']) : null;
        $appeal = Dispute::query()->where('appeal_of_dispute_id', $this->id)->first();
        $operations = app(DisputeOperations::class);

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'claim' => ($claim = Claim::query()->where('dispute_id', $this->id)->first()) ? ['id' => $claim->id, 'reference' => $claim->reference] : null,
            'decision' => $this->decision,
            'decided_by' => $this->decidedByAdmin?->fullName(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'appeal_deadline' => $this->appeal_deadline?->toIso8601String(),
            'outcome' => $impact['outcome'] ?? null,
            'financial' => [
                'refund_amount' => $impact['refund_amount'] ?? null,
                'note' => $impact['note'] ?? null,
                'refund_pending' => $impact['refund_pending'] ?? false,
                'refund' => $refund ? ['id' => $refund->id, 'reference' => $refund->reference, 'status' => $this->status($refund->status)] : null,
            ],
            'appeal' => $appeal ? ['id' => $appeal->id, 'reference' => $appeal->reference, 'status' => $this->status($appeal->status)] : null,
            'messages' => $this->messages()->with('author')->oldest()->orderBy('id')->get()->map(fn (DisputeMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'internal' => $message->is_internal,
                'author' => match (true) {
                    $message->author instanceof Admin => $message->author->fullName(),
                    $message->author instanceof User => trim($message->author->first_name.' '.$message->author->last_name) ?: 'Partie',
                    default => '—',
                },
                'from_team' => $message->author instanceof Admin,
                'date' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
            'proofs' => $this->attachments()->with('mediaFile')->oldest()->get()->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'label' => $attachment->label,
                'url' => $attachment->mediaFile ? $media->temporaryUrl($attachment->mediaFile) : null,
                'from_team' => $attachment->created_by_type === (new Admin)->getMorphClass(),
                'date' => $attachment->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => AuditLog::query()
                ->where('subject_type', $this->resource->getMorphClass())
                ->where('subject_id', $this->id)
                ->latest()
                ->with('actor')
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'reason' => $log->after_values['reason'] ?? null,
                    'by' => $log->actor instanceof Admin ? $log->actor->fullName() : null,
                    'date' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
            'can' => $operations->actions($this->resource),
        ];
    }
}
