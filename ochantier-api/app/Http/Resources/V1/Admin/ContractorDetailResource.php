<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Contractor\ContractorProfileResource;
use App\Models\Contractor;
use App\Models\VerificationReview;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Fiche d’un entrepreneur dans le Back Office : dossier complet (comme dans l’application), compte, historique des
 * demandes de vérification et décisions possibles.
 *
 * @mixin Contractor
 */
class ContractorDetailResource extends ContractorProfileResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $pending = $this->verification_status->isPending();

        return [
            ...parent::toArray($request),
            'status' => $this->status($this->status),
            'suspended_reason' => $this->suspended_reason,
            'account' => $user ? [
                'id' => $user->id,
                'status' => $this->status($user->status),
                'phone' => PhoneNumber::of($user)?->toArray()['phone'],
                'email' => $user->email,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ] : null,
            'reviews' => $this->verificationReviews->sortByDesc('submission_number')->map(fn (VerificationReview $review) => [
                'id' => $review->id,
                'submission_number' => $review->submission_number,
                'status' => $this->status($review->status),
                'submitted_at' => $review->submitted_at?->toIso8601String(),
                'decision_reason' => $review->decision_reason,
                'decided_at' => $review->decided_at?->toIso8601String(),
                'decided_by' => $review->decidedByAdmin?->only(['id', 'first_name', 'last_name']),
            ])->values(),
            'actions' => [
                'approve' => $pending,
                'request_corrections' => $pending,
                'reject' => $pending,
                'suspend' => $this->status->value === 'actif',
                'reactivate' => $this->status->value === 'suspendu',
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
