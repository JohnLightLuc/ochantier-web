<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\OrganizationMemberStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Admin;
use App\Models\OrganizationMember;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionStatusHistory;
use App\Support\Admin\SubscriptionOperations;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\Request;

/**
 * Fiche d’un abonnement : compte, formule, utilisateurs, factures, journal et actions possibles.
 *
 * @mixin Subscription
 */
class SubscriptionDetailResource extends SubscriptionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscriptions = app(PromoterSubscriptions::class);
        $owner = $this->promoterProfile->user;
        $lastPayment = PaymentTransaction::query()
            ->whereIn('subscription_invoice_id', $this->invoices()->select('id'))
            ->where('status', PaymentTransactionStatus::Reussie)
            ->with('operator')
            ->latest('completed_at')
            ->first();

        return [
            ...parent::toArray($request),
            'contact' => ['phone' => $owner->phone_number, 'email' => $owner->email],
            'period_start' => $this->date($this->current_period_start),
            'previous_plan' => $this->previousPlan?->name,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'payment_operator' => $lastPayment?->operator?->name,
            'users' => $this->users(),
            'invoices' => $this->invoices()->with('paymentTransactions.receipts.mediaFile')->latest('period_start')->get()->map(fn (SubscriptionInvoice $invoice) => $subscriptions->invoice($invoice))->values()->all(),
            'history' => $this->statusHistories()->with(['fromPlan', 'toPlan'])->latest()->latest('id')->get()->map(fn (SubscriptionStatusHistory $entry) => $this->entry($entry))->values()->all(),
            'can' => app(SubscriptionOperations::class)->actions($this->resource),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(): array
    {
        $profile = $this->promoterProfile;
        $members = $profile->user->ownedOrganizations()->first()?->members()
            ->whereIn('status', [OrganizationMemberStatus::Invite, OrganizationMemberStatus::Actif])
            ->where('user_id', '!=', $profile->user_id)
            ->with('user')
            ->oldest()
            ->get() ?? collect();

        return collect([['id' => $profile->user_id, 'name' => $this->ownerName(), 'role' => PromoterAccess::ROLE_LABELS[PromoterAccess::OWNER], 'status' => 'Actif']])
            ->concat($members->map(fn (OrganizationMember $member) => [
                'id' => $member->user_id,
                'name' => trim($member->user->first_name.' '.$member->user->last_name) ?: $member->user->phone_number,
                'role' => PromoterAccess::ROLE_LABELS[$member->role->value] ?? $member->role->value,
                'status' => $member->status->label(),
            ]))
            ->values()
            ->all();
    }

    private function ownerName(): string
    {
        $user = $this->promoterProfile->user;

        return trim($user->first_name.' '.$user->last_name) ?: (string) $user->phone_number;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(SubscriptionStatusHistory $entry): array
    {
        $changed = $entry->from_status !== $entry->to_status;

        return [
            'id' => $entry->id,
            'date' => $entry->created_at?->toIso8601String(),
            'reason' => $entry->reason,
            'status' => $changed ? $entry->to_status->label() : null,
            'actor' => match ($entry->actor_type) {
                'systeme' => 'Système',
                (new Admin)->getMorphClass() => Admin::query()->find($entry->actor_id)?->fullName() ?? 'Administrateur',
                default => 'Promoteur',
            },
        ];
    }
}
