<?php

namespace App\Support\Promoter;

use App\Enums\ClaimCategory;
use App\Models\Attachment;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Admin\DisputeOperations;
use Illuminate\Database\Eloquent\Builder;

/**
 * Litiges dont le compte promoteur est partie : suivi, échanges avec l’équipe, preuves et recours. Les messages
 * internes de l’équipe n’y apparaissent jamais.
 */
class PromoterDisputes
{
    public function __construct(private DisputeOperations $operations) {}

    /**
     * @return Builder<Dispute>
     */
    private function query(PromoterProfile $profile): Builder
    {
        return Dispute::query()
            ->whereHas('parties', fn (Builder $query) => $query->where('party_type', $profile->getMorphClass())->where('party_id', $profile->getKey()))
            ->with('site');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(PromoterProfile $profile): array
    {
        return $this->query($profile)->latest()->orderBy('id')->get()->map(fn (Dispute $dispute) => $this->row($dispute))->all();
    }

    public function find(PromoterProfile $profile, string $id): Dispute
    {
        abort_unless(str($id)->isUuid(), 404);

        return $this->query($profile)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'reference' => $dispute->reference,
            'category' => ['value' => $dispute->category, 'label' => ClaimCategory::tryFrom($dispute->category)?->label() ?? $dispute->category],
            'description' => $dispute->description,
            'site' => $dispute->site ? ['id' => $dispute->site->id, 'title' => $dispute->site->label] : null,
            'status' => ['value' => $dispute->status->value, 'label' => $dispute->status->label()],
            'decision' => $dispute->decision,
            'decided_at' => $dispute->decided_at?->toIso8601String(),
            'appeal_deadline' => $dispute->appeal_deadline?->toIso8601String(),
            'can_appeal' => $this->operations->actions($dispute)['appeal'],
            'created_at' => $dispute->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Dispute $dispute, User $viewer): array
    {
        return [
            ...$this->row($dispute),
            'messages' => $dispute->messages()->where('is_internal', false)->oldest()->orderBy('id')->get()->map(fn (DisputeMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'mine' => $message->author_type === $viewer->getMorphClass() && $message->author_id === $viewer->getKey(),
                'from_team' => $message->author_type === 'admin',
                'date' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
            'proofs' => $dispute->attachments()->where('created_by_type', $viewer->getMorphClass())->oldest()->get()->map(fn (Attachment $attachment) => ['id' => $attachment->id, 'label' => $attachment->label])->values()->all(),
        ];
    }
}
