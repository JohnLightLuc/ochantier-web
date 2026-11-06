<?php

namespace App\Support\Admin;

use App\Enums\CommercialAdjustmentStatus;
use App\Enums\CommercialPayoutStatus;
use App\Enums\DisputeStatus;
use App\Enums\PaymentTransactionPurpose;
use App\Models\Admin;
use App\Models\CommercialAdjustment;
use App\Models\CommercialEarning;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Models\Dispute;
use App\Support\Commercial\CommercialEarnings;
use App\Support\Commercial\CommercialNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versement mensuel aux commerciaux : commissions confirmées, primes et retenues approuvées. Une personne prépare le
 * versement d’un mois terminé, une autre le valide, ce qui l’envoie.
 */
class CommercialPayouts
{
    private const OPEN_DISPUTES = [DisputeStatus::Ouvert, DisputeStatus::EnInstruction, DisputeStatus::EnRecours];

    public function __construct(
        private CommercialEarnings $earnings,
        private CommercialNotifier $notifier,
        private PayoutMethods $methods,
        private Disbursements $disbursements,
    ) {}

    /**
     * Commerciaux à qui quelque chose reste dû (ou retenu) à la fin du mois.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payable(CarbonImmutable $month): Collection
    {
        $end = $month->endOfMonth();

        return CommercialProfile::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('commercialEarnings', fn (Builder $query) => $query->whereIn('status', ['acquis', 'approuve'])->whereNull('commercial_payout_id')->where('event_at', '<=', $end))
                ->orWhereHas('commercialAdjustments', fn (Builder $query) => $query->where('status', CommercialAdjustmentStatus::Approuvee)->whereNull('commercial_payout_id')->where('granted_on', '<=', $end->toDateString())))
            ->with('user')
            ->orderBy('employee_number')
            ->get()
            ->map(fn (CommercialProfile $commercial) => $this->row($commercial, $month));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(CommercialProfile $commercial, CarbonImmutable $month): array
    {
        $due = $this->earnings->due($commercial, $month);
        $method = $this->methods->current($commercial);

        return [
            'commercial' => ['id' => $commercial->id, 'reference' => $commercial->employee_number, 'full_name' => trim($commercial->user->first_name.' '.$commercial->user->last_name)],
            'earnings_count' => $due['earnings']->count(),
            'commissions' => $due['commissions'],
            'bonuses' => $due['bonuses'],
            'deductions' => $due['deductions'],
            'total' => $due['total'],
            'number' => $method?->account_number_masked,
            'operator' => $method?->operator?->name,
            'blockers' => $this->blockers($commercial, $month, $due),
        ];
    }

    public function prepare(CommercialProfile $commercial, CarbonImmutable $month, Admin $admin, Request $request): CommercialPayout
    {
        if ($month->endOfMonth()->isFuture()) {
            throw ValidationException::withMessages(['payout' => 'Ce mois n’est pas terminé.']);
        }

        return DB::transaction(function () use ($commercial, $month, $admin, $request): CommercialPayout {
            $due = $this->earnings->due($commercial, $month, true);

            if ($blockers = $this->blockers($commercial, $month, $due)) {
                throw ValidationException::withMessages(['payout' => $blockers[0].'.']);
            }

            $payout = $this->earnings->attach($commercial, $month, CommercialPayoutStatus::APayer, $admin->getKey())
                ?? throw ValidationException::withMessages(['payout' => 'Rien à verser pour ce commercial.']);

            AdminAudit::record($admin, 'commercial_earnings', 'commercial.payout_prepared', $request, $commercial, null, ['payout_id' => $payout->id, 'amount' => $payout->total_amount]);

            return $payout;
        });
    }

    /**
     * La validation par une autre personne envoie le versement.
     */
    public function approve(CommercialPayout $payout, Admin $admin, Request $request): CommercialPayout
    {
        DB::transaction(function () use ($payout, $admin, $request): void {
            $locked = $this->lock($payout, CommercialPayoutStatus::APayer);
            $this->requireOtherPerson($locked, $admin);
            $this->requireNoDispute($locked);

            $locked->update(['status' => CommercialPayoutStatus::EnTraitement, 'approved_by_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'commercial_earnings', 'commercial.payout_approved', $request, $locked->commercial, null, ['payout_id' => $locked->id, 'amount' => $locked->total_amount]);
        });

        return $this->send($payout->refresh(), $admin);
    }

    /**
     * Renvoie un versement rejeté, sur le numéro confirmé actuel du commercial.
     */
    public function retry(CommercialPayout $payout, Admin $admin, Request $request): CommercialPayout
    {
        DB::transaction(function () use ($payout, $admin, $request): void {
            $locked = $this->lock($payout, CommercialPayoutStatus::Rejete);
            $this->requireOtherPerson($locked, $admin);
            $this->requireNoDispute($locked);

            $locked->update(['status' => CommercialPayoutStatus::EnTraitement]);
            AdminAudit::record($admin, 'commercial_earnings', 'commercial.payout_retried', $request, $locked->commercial, null, ['payout_id' => $locked->id]);
        });

        return $this->send($payout->refresh(), $admin);
    }

    /**
     * Annule un versement non parti : commissions et ajustements redeviennent dus.
     */
    public function cancel(CommercialPayout $payout, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($payout, $reason, $admin, $request): void {
            $locked = $this->lock($payout, CommercialPayoutStatus::APayer, CommercialPayoutStatus::Rejete);

            CommercialEarning::query()->where('commercial_payout_id', $locked->getKey())->update(['commercial_payout_id' => null]);
            CommercialAdjustment::query()->where('commercial_payout_id', $locked->getKey())->update(['commercial_payout_id' => null]);
            $locked->update(['status' => CommercialPayoutStatus::Annule]);

            AdminAudit::record($admin, 'commercial_earnings', 'commercial.payout_cancelled', $request, $locked->commercial, null, ['payout_id' => $locked->id, 'reason' => $reason]);
        });
    }

    private function send(CommercialPayout $payout, Admin $admin): CommercialPayout
    {
        $commercial = $payout->commercial()->with('user')->firstOrFail();
        $method = $this->methods->current($commercial);

        if (! $method) {
            $payout->update(['status' => CommercialPayoutStatus::Rejete]);

            throw ValidationException::withMessages(['payout' => 'Ce commercial n’a pas de numéro confirmé.']);
        }

        $succeeded = $this->disbursements->send(
            $method,
            $payout->total_amount,
            $payout->paymentTransactions(),
            ['purpose' => PaymentTransactionPurpose::GainCommercial, 'payee_type' => 'commercial_profile', 'payee_id' => $payout->commercial_id, 'commercial_payout_id' => $payout->getKey()],
            'Versement '.$payout->reference,
            'gain-commercial:'.$payout->getKey(),
            $admin,
            fn () => $payout->update(['payment_method_id' => $method->getKey()]),
            fn (bool $succeeded) => $succeeded ? $this->earnings->settle($payout) : $payout->update(['status' => CommercialPayoutStatus::Rejete]),
        );

        if ($succeeded) {
            $this->notifier->payoutPaid($payout->load('commercial.user'));
        }

        return $payout->refresh();
    }

    /**
     * @param  array{earnings: Collection<int, CommercialEarning>, total: int}  $due
     * @return list<string>
     */
    private function blockers(CommercialProfile $commercial, CarbonImmutable $month, array $due): array
    {
        $proposed = $commercial->commercialAdjustments()->where('status', CommercialAdjustmentStatus::Proposee)->where('granted_on', '<=', $month->endOfMonth()->toDateString())->exists();
        $disputed = Dispute::query()->whereIn('status', self::OPEN_DISPUTES)->whereIn('commercial_earning_id', $due['earnings']->modelKeys())->exists();

        return array_values(array_filter([
            $due['total'] <= 0 ? 'Solde nul ou négatif : reporté sur le prochain versement' : null,
            $disputed ? 'Litige ouvert sur une commission' : null,
            $proposed ? 'Prime ou retenue à décider' : null,
            $this->methods->current($commercial) ? null : 'Aucun numéro Mobile Money confirmé',
        ]));
    }

    private function lock(CommercialPayout $payout, CommercialPayoutStatus ...$statuses): CommercialPayout
    {
        $locked = CommercialPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages(['payout' => 'Ce versement a déjà changé d’état. Rechargez la page.']);
        }

        return $locked;
    }

    private function requireOtherPerson(CommercialPayout $payout, Admin $admin): void
    {
        if ($payout->created_by_admin_id === $admin->getKey()) {
            throw ValidationException::withMessages(['payout' => 'Une autre personne doit valider.']);
        }
    }

    private function requireNoDispute(CommercialPayout $payout): void
    {
        if (Dispute::query()->whereIn('status', self::OPEN_DISPUTES)->whereIn('commercial_earning_id', $payout->commercialEarnings()->select('id'))->exists()) {
            throw ValidationException::withMessages(['payout' => 'Un litige ouvert bloque ce versement.']);
        }
    }
}
