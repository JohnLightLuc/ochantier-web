<?php

namespace App\Support\Admin;

use App\Enums\ContractorPayoutStatus;
use App\Enums\PaymentTransactionPurpose;
use App\Models\Admin;
use App\Models\Contractor;
use App\Models\ContractorPayout;
use App\Support\Contractor\ContractorNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reversements aux professionnels O’CHANTIER PRO de leur part nette : frais de visite d’un devis une fois la visite
 * faite, part nette de l’acompte et des échéances d’un forfait une fois payés par le promoteur. Une personne prépare
 * le versement d’un professionnel (toutes ses parts dues), une autre le valide, ce qui l’envoie par Mobile Money sur
 * son numéro confirmé. Un versement rejeté se relance ; un versement non parti s’annule et ses parts redeviennent dues.
 */
class ContractorPayouts
{
    public function __construct(
        private ProCommissionLedger $ledger,
        private PayoutMethods $methods,
        private Disbursements $disbursements,
        private ContractorNotifier $notifier,
    ) {}

    /**
     * Professionnels à qui une part nette est due, avec le total, le numéro et ce qui bloque le versement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payable(): Collection
    {
        $lines = $this->ledger->payable()->groupBy('contractor.id');
        $contractors = Contractor::query()->whereKey($lines->keys())->get()->keyBy('id');

        return $lines->map(function (Collection $items, string $id) use ($contractors): array {
            $contractor = $contractors->get($id);
            $method = $this->methods->current($contractor);

            return [
                'contractor' => ['id' => $contractor->id, 'reference' => $contractor->reference, 'name' => $contractor->name],
                'items_count' => $items->count(),
                'total' => (int) $items->sum('professional_net'),
                'number' => $method?->account_number_masked,
                'operator' => $method?->operator?->name,
                'blockers' => $this->blockers($contractor),
                'items' => $items->map(fn (array $line) => $this->item($line))->values()->all(),
            ];
        })->sortByDesc('total')->values();
    }

    /**
     * Réunit toutes les parts dues au professionnel dans un versement « à valider ».
     */
    public function prepare(Contractor $contractor, Admin $admin, Request $request): ContractorPayout
    {
        return DB::transaction(function () use ($contractor, $admin, $request): ContractorPayout {
            Contractor::query()->whereKey($contractor->getKey())->lockForUpdate()->first();

            if ($blockers = $this->blockers($contractor)) {
                throw ValidationException::withMessages(['payout' => $blockers[0].'.']);
            }

            $lines = $this->ledger->payable($contractor->getKey());

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['payout' => 'Rien à verser à ce professionnel.']);
            }

            $payout = $contractor->payouts()->create([
                'reference' => sprintf('VP-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('contractor_payout_reference_seq')")),
                'total_amount' => (int) $lines->sum('professional_net'),
                'status' => ContractorPayoutStatus::APayer,
                'created_by_admin_id' => $admin->getKey(),
            ]);
            $payout->items()->createMany($lines->map(fn (array $line) => [
                'source_key' => $line['id'],
                'reference' => $line['reference'],
                'label' => $line['label'],
                'site_label' => $line['site']['label'],
                'amount' => $line['professional_net'],
            ])->all());

            AdminAudit::record($admin, 'payouts', 'contractor.payout_prepared', $request, $contractor, null, ['payout_id' => $payout->id, 'amount' => $payout->total_amount, 'items' => $lines->count()]);

            return $payout;
        });
    }

    /**
     * La validation par une autre personne envoie le versement.
     */
    public function approve(ContractorPayout $payout, Admin $admin, Request $request): ContractorPayout
    {
        DB::transaction(function () use ($payout, $admin, $request): void {
            $locked = $this->lock($payout, ContractorPayoutStatus::APayer);
            $this->requireOtherPerson($locked, $admin);

            $locked->update(['status' => ContractorPayoutStatus::EnTraitement, 'approved_by_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'payouts', 'contractor.payout_approved', $request, $locked->contractor, null, ['payout_id' => $locked->id, 'amount' => $locked->total_amount]);
        });

        return $this->send($payout->refresh(), $admin);
    }

    /**
     * Renvoie un versement rejeté, sur le numéro confirmé actuel du professionnel.
     */
    public function retry(ContractorPayout $payout, Admin $admin, Request $request): ContractorPayout
    {
        DB::transaction(function () use ($payout, $admin, $request): void {
            $locked = $this->lock($payout, ContractorPayoutStatus::Rejete);
            $this->requireOtherPerson($locked, $admin);

            $locked->update(['status' => ContractorPayoutStatus::EnTraitement]);
            AdminAudit::record($admin, 'payouts', 'contractor.payout_retried', $request, $locked->contractor, null, ['payout_id' => $locked->id]);
        });

        return $this->send($payout->refresh(), $admin);
    }

    /**
     * Annule un versement non parti : ses parts redeviennent dues.
     */
    public function cancel(ContractorPayout $payout, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($payout, $reason, $admin, $request): void {
            $locked = $this->lock($payout, ContractorPayoutStatus::APayer, ContractorPayoutStatus::Rejete);

            $locked->items()->delete();
            $locked->update(['status' => ContractorPayoutStatus::Annule, 'cancellation_reason' => $reason]);

            AdminAudit::record($admin, 'payouts', 'contractor.payout_cancelled', $request, $locked->contractor, null, ['payout_id' => $locked->id, 'reason' => $reason]);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function item(array $line): array
    {
        return [
            'source_key' => $line['id'],
            'source' => $line['source'],
            'reference' => $line['reference'],
            'label' => $line['label'],
            'site' => $line['site']['label'],
            'amount' => $line['professional_net'],
            'date' => $line['date'],
        ];
    }

    private function send(ContractorPayout $payout, Admin $admin): ContractorPayout
    {
        $contractor = $payout->contractor()->firstOrFail();
        $method = $this->methods->current($contractor);

        if (! $method) {
            $payout->update(['status' => ContractorPayoutStatus::Rejete]);

            throw ValidationException::withMessages(['payout' => 'Ce professionnel n’a pas de numéro confirmé.']);
        }

        $succeeded = $this->disbursements->send(
            $method,
            $payout->total_amount,
            $payout->paymentTransactions(),
            ['purpose' => PaymentTransactionPurpose::ReversementProfessionnel, 'payee_type' => 'contractor', 'payee_id' => $contractor->getKey(), 'contractor_payout_id' => $payout->getKey()],
            'Versement '.$payout->reference,
            'reversement-professionnel:'.$payout->getKey(),
            $admin,
            fn () => $payout->update(['payment_method_id' => $method->getKey()]),
            fn (bool $succeeded) => $payout->update($succeeded ? ['status' => ContractorPayoutStatus::Paye, 'paid_at' => now()] : ['status' => ContractorPayoutStatus::Rejete]),
        );

        if ($succeeded) {
            $this->notifier->notify($contractor, 'payout.paid', 'Versement envoyé',
                number_format($payout->total_amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA versés sur votre numéro ".$method->account_number_masked.' ('.$payout->reference.').',
                ['type' => 'payouts', 'id' => $payout->id], $payout);
        }

        return $payout->refresh();
    }

    /**
     * @return list<string>
     */
    private function blockers(Contractor $contractor): array
    {
        $open = $contractor->payouts()->whereIn('status', [ContractorPayoutStatus::APayer->value, ContractorPayoutStatus::EnTraitement->value, ContractorPayoutStatus::Rejete->value])->exists();

        return array_values(array_filter([
            $open ? 'Un versement est déjà en cours pour ce professionnel' : null,
            $this->methods->current($contractor) ? null : 'Aucun numéro Mobile Money confirmé',
        ]));
    }

    private function lock(ContractorPayout $payout, ContractorPayoutStatus ...$statuses): ContractorPayout
    {
        $locked = ContractorPayout::query()->whereKey($payout->getKey())->lockForUpdate()->with('contractor')->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages(['payout' => 'Ce versement est déjà traité (statut : '.$locked->status->label().').']);
        }

        return $locked;
    }

    private function requireOtherPerson(ContractorPayout $payout, Admin $admin): void
    {
        if ($payout->created_by_admin_id === $admin->getKey()) {
            throw ValidationException::withMessages(['payout' => 'Une autre personne que celle qui l’a préparé doit valider ce versement.']);
        }
    }
}
