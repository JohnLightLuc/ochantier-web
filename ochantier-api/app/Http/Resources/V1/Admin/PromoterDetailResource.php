<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Promoter\PromoterProfileResource;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\PromoterProfile;
use App\Models\VerificationCorrection;
use App\Models\VerificationReview;
use App\Support\Admin\PromoterVerification;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\Request;

/**
 * Fiche promoteur du Back Office : profil tel que le voit le promoteur (identité, entreprise, complétude, pièces),
 * complété par le compte, le contrôle en cours et son historique, l’abonnement et les signalements.
 *
 * @mixin PromoterProfile
 */
class PromoterDetailResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = app(PromoterVerification::class);
        $user = $this->user;
        $reviews = $this->verificationReviews()->with(['assignedAdmin', 'decidedByAdmin', 'verificationCorrections.document.documentType'])->orderByDesc('submission_number')->get();
        $subscription = app(PromoterSubscriptions::class)->live($this->resource);
        $refusal = $verification->approvalRefusal($this->resource);

        return [
            ...PromoterProfileResource::make($this->resource)->resolve($request),
            'status' => PromoterListResource::displayStatus($this->resource),
            'account' => [
                'status' => $this->status($user->status),
                'suspended_at' => $user->suspended_at?->toIso8601String(),
                'suspended_reason' => $user->suspended_reason,
                'registered_at' => $this->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'validated_at' => $this->validated_at?->toIso8601String(),
                'validated_by' => $this->validatedByAdmin ? $this->admin($this->validatedByAdmin) : null,
            ],
            'review' => [
                'can_decide' => in_array($this->verification_status, PromoterVerification::REVIEWABLE, true),
                'can_approve' => $refusal === null,
                'approval_refusal' => $refusal,
                'required_documents' => array_map(fn (array $item, string $key) => [
                    'key' => $key,
                    'label' => $item[0],
                    'document_id' => $item[1]?->id,
                    'status' => $item[1] ? $this->status($item[1]->verification_status) : null,
                ], $required = $verification->requiredDocuments($this->resource), array_keys($required)),
                'current' => ($current = $reviews->first()) ? $this->review($current) : null,
                'history' => $reviews->skip(1)->map(fn (VerificationReview $review) => $this->review($review))->values()->all(),
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'reference' => $subscription->reference,
                'plan' => $subscription->plan?->only(['id', 'code', 'name']),
                'status' => $this->status($subscription->status),
                'current_period_end' => $this->date($subscription->current_period_end),
            ] : null,
            'flags' => [
                'expired_documents_count' => (int) ($this->expired_documents_count ?? 0),
                'open_disputes_count' => (int) ($this->open_disputes_count ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function review(VerificationReview $review): array
    {
        return [
            'id' => $review->id,
            'submission_number' => $review->submission_number,
            'status' => $this->status($review->status),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'assigned_admin' => $review->assignedAdmin ? $this->admin($review->assignedAdmin) : null,
            'decided_by' => $review->decidedByAdmin ? $this->admin($review->decidedByAdmin) : null,
            'decided_at' => $review->decided_at?->toIso8601String(),
            'decision_reason' => $review->decision_reason,
            'corrections' => $review->verificationCorrections->map(fn (VerificationCorrection $correction) => [
                'id' => $correction->id,
                'document' => $correction->document ? ['id' => $correction->document->id, 'type' => $correction->document->documentType->only(['code', 'label'])] : null,
                'field' => $correction->target_field,
                'reason' => $correction->reason,
                'resolved_at' => $correction->resolved_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array{id: string, full_name: string}
     */
    private function admin(Admin $admin): array
    {
        return ['id' => $admin->id, 'full_name' => $admin->fullName()];
    }
}
