<?php

namespace App\Support\Admin;

use App\Enums\CommercialAdjustmentKind;
use App\Enums\CommercialAdjustmentStatus;
use App\Models\Admin;
use App\Models\CommercialAdjustment;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Support\Commercial\CommercialEarnings;
use App\Support\Commercial\CommercialNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gains d’un commercial dans le Back Office : commissions du mois, versements, et primes ou retenues soumises à une
 * double validation. Le versement lui-même relève du module Paiements.
 */
class CommercialCompensation
{
    public function __construct(private CommercialEarnings $earnings, private CommercialNotifier $notifier) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(CommercialProfile $commercial, CarbonImmutable $month, Admin $admin): array
    {
        return [
            ...$this->earnings->month($commercial, $month),
            'months' => $this->earnings->months($commercial),
            'adjustments' => $commercial->commercialAdjustments()->with(['createdByAdmin', 'approvedByAdmin'])->latest()->latest('id')->limit(30)->get()->map(fn (CommercialAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'kind' => ['value' => $adjustment->kind->value, 'label' => $adjustment->kind->label()],
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
                'granted_on' => $adjustment->granted_on?->toDateString(),
                'status' => ['value' => $adjustment->status->value, 'label' => $adjustment->status->label()],
                'proposed_by' => ['id' => $adjustment->created_by_admin_id, 'full_name' => trim($adjustment->createdByAdmin->first_name.' '.$adjustment->createdByAdmin->last_name)],
                'decided_by' => $adjustment->approvedByAdmin ? trim($adjustment->approvedByAdmin->first_name.' '.$adjustment->approvedByAdmin->last_name) : null,
                'can_decide' => $adjustment->status === CommercialAdjustmentStatus::Proposee && $adjustment->created_by_admin_id !== $admin->getKey(),
            ])->all(),
            'payouts' => $commercial->commercialPayouts()->latest()->limit(12)->get()->map(fn (CommercialPayout $payout) => [
                'id' => $payout->id,
                'reference' => $payout->reference,
                'period' => ['start' => $payout->period_start?->toDateString(), 'end' => $payout->period_end?->toDateString()],
                'amount' => $payout->total_amount,
                'status' => ['value' => $payout->status->value, 'label' => $payout->status->label()],
                'paid_at' => $payout->paid_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @param  array{kind: string, amount: int, reason: string, granted_on?: string|null}  $data
     */
    public function propose(CommercialProfile $commercial, array $data, Admin $admin, Request $request): CommercialAdjustment
    {
        $adjustment = $commercial->commercialAdjustments()->create([
            'kind' => $data['kind'],
            'amount' => $data['amount'],
            'reason' => trim($data['reason']),
            'granted_on' => $data['granted_on'] ?? today()->toDateString(),
            'status' => CommercialAdjustmentStatus::Proposee,
            'created_by_admin_id' => $admin->getKey(),
        ]);

        AdminAudit::record($admin, 'commercial_earnings', 'commercial.adjustment_proposed', $request, $commercial, null, ['adjustment_id' => $adjustment->id, 'kind' => $data['kind'], 'amount' => $data['amount'], 'reason' => $adjustment->reason]);

        return $adjustment;
    }

    public function approve(CommercialProfile $commercial, CommercialAdjustment $adjustment, Admin $admin, Request $request): void
    {
        $this->decide($adjustment, $admin);
        $adjustment->update(['status' => CommercialAdjustmentStatus::Approuvee, 'approved_by_admin_id' => $admin->getKey()]);

        AdminAudit::record($admin, 'commercial_earnings', 'commercial.adjustment_approved', $request, $commercial, null, ['adjustment_id' => $adjustment->id, 'amount' => $adjustment->amount]);
        $this->notifier->adjustmentApproved($commercial, $adjustment, ($adjustment->kind === CommercialAdjustmentKind::Prime ? 'Prime' : 'Retenue').' de '.$this->earnings->money($adjustment->amount).' : '.$adjustment->reason);
    }

    public function refuse(CommercialProfile $commercial, CommercialAdjustment $adjustment, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($adjustment, $admin);
        $adjustment->update(['status' => CommercialAdjustmentStatus::Refusee, 'approved_by_admin_id' => $admin->getKey()]);

        AdminAudit::record($admin, 'commercial_earnings', 'commercial.adjustment_refused', $request, $commercial, null, ['adjustment_id' => $adjustment->id, 'reason' => $reason]);
    }

    /**
     * Seule une autre personne que l’auteur décide d’une proposition en attente.
     */
    private function decide(CommercialAdjustment $adjustment, Admin $admin): void
    {
        if ($adjustment->status !== CommercialAdjustmentStatus::Proposee) {
            throw ValidationException::withMessages(['commercial' => 'Cette proposition a déjà été traitée.']);
        }

        if ($adjustment->created_by_admin_id === $admin->getKey()) {
            throw ValidationException::withMessages(['commercial' => 'Une autre personne doit décider de cette proposition.']);
        }
    }
}
