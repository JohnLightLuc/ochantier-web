<?php

namespace App\Support\Promoter;

use App\Enums\MissionStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PricingVariable;
use App\Enums\ProposalStatus;
use App\Enums\ReceiptType;
use App\Enums\TransactionStatusSource;
use App\Enums\WorkerReservationStatus;
use App\Models\Mission;
use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\PromoterProfile;
use App\Models\Proposal;
use App\Models\Receipt;
use App\Models\Site;
use App\Models\User;
use App\Support\Documents\SimplePdf;
use App\Support\Media\MediaStore;
use App\Support\Payments\PaymentGateway;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paiements du promoteur (« Paiements », « Payer ») : acompte d’une proposition acceptée et échéances des missions,
 * par Mobile Money.
 *
 * Un paiement porte sur l’acompte d’une proposition (il crée alors les missions et leur échéancier) ou sur une
 * échéance (regroupant les missions d’une même réservation). Les frais de l’opérateur s’ajoutent au montant ; un
 * reçu PDF est émis pour chaque paiement réussi.
 */
class PromoterPayments
{
    /**
     * États affichés d’une ligne de paiement.
     *
     * @var array<string, string>
     */
    public const STATES = [
        'en_retard' => 'En retard',
        'a_payer' => 'À payer',
        'a_venir' => 'À venir',
        'effectue' => 'Effectué',
        'finalise' => 'Finalisé',
    ];

    public function __construct(
        private PricingRules $pricing,
        private MissionPlanner $planner,
        private PaymentGateway $gateway,
        private MediaStore $media,
    ) {}

    /**
     * Opérateurs Mobile Money proposés au paiement.
     *
     * @return Collection<int, PaymentOperator>
     */
    public function operators(): Collection
    {
        return PaymentOperator::query()->where('is_active', true)->where('supports_collection', true)->orderBy('created_at')->get();
    }

    /**
     * Ce que règle le paiement demandé : acompte d’une proposition acceptée, ou échéances non payées d’une même
     * réservation.
     *
     * @param  array{proposal_id?: string|null, schedule_ids?: list<string>|null}  $target
     * @return array{kind: string, label: string, amount: int, site: Site, proposal: Proposal|null, schedules: Collection<int, PaymentSchedule>, lines: list<array{label: string, amount: int}>, done_text: string}
     */
    public function payable(User $user, array $target): array
    {
        return isset($target['proposal_id']) ? $this->deposit($user, $target['proposal_id']) : $this->installments($user, $target['schedule_ids'] ?? []);
    }

    /**
     * Détail du montant à payer avec l’opérateur choisi (« Montant à régler », « Frais opérateur », total).
     *
     * @param  array{amount: int, lines: list<array{label: string, amount: int}>}  $payable
     * @return array{lines: list<array{label: string, amount: int}>, amount: int, fees: int, total: int, fee_percent: float}
     */
    public function quote(array $payable, PaymentOperator $operator): array
    {
        $percent = (float) $operator->collection_fee_percent;
        $fees = $this->pricing->operatorFee($payable['amount'], $percent);

        return [
            'lines' => [...$payable['lines'], ['label' => 'Frais opérateur '.$operator->name.' '.$this->percent($percent), 'amount' => $fees]],
            'amount' => $payable['amount'],
            'fees' => $fees,
            'total' => $payable['amount'] + $fees,
            'fee_percent' => $percent,
        ];
    }

    /**
     * Paie par Mobile Money. Une même clé d’idempotence renvoie le paiement déjà demandé.
     *
     * @param  array<string, mixed>  $payable
     */
    public function pay(User $user, PromoterProfile $profile, array $payable, PaymentOperator $operator, PhoneNumber $phone, string $idempotencyKey, bool $remember = false): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $quote = $this->quote($payable, $operator);
        $method = $remember ? $this->rememberNumber($profile, $user, $operator, $phone) : null;

        $transaction = PaymentTransaction::query()->create([
            'reference' => sprintf('PAY-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_transaction_reference_seq')")),
            'direction' => PaymentTransactionDirection::Encaissement,
            'purpose' => $payable['kind'] === 'acompte' ? PaymentTransactionPurpose::Acompte : PaymentTransactionPurpose::Echeance,
            'payer_type' => $profile->getMorphClass(),
            'payer_id' => $profile->getKey(),
            'payee_type' => 'plateforme',
            'payment_schedule_id' => $payable['schedules']->count() === 1 ? $payable['schedules']->first()->getKey() : null,
            'payment_method_id' => $method?->getKey(),
            'operator_id' => $operator->getKey(),
            'provider' => $operator->provider,
            'idempotency_key' => $idempotencyKey,
            'amount' => $quote['amount'],
            'fees_amount' => $quote['fees'],
            'status' => PaymentTransactionStatus::Initiee,
            'initiated_by_type' => $user->getMorphClass(),
            'initiated_by_id' => $user->getKey(),
            'metadata' => [
                'allocations' => [],
                'label' => $payable['label'],
                'site_id' => $payable['site']->getKey(),
                'proposal_id' => $payable['proposal']?->getKey(),
                'schedule_ids' => $this->keys($payable['schedules']),
                'phone' => $phone->masked(),
                'lines' => $quote['lines'],
                'done_text' => $payable['done_text'],
            ],
        ]);
        $this->history($transaction, null, PaymentTransactionStatus::Initiee, $user);

        $result = $this->gateway->collect($operator, $phone, $quote['total'], $transaction->reference);

        if ($result->status !== PaymentTransactionStatus::Reussie->value) {
            $transaction->forceFill(['status' => PaymentTransactionStatus::Echouee, 'provider_reference' => $result->providerReference, 'failure_code' => $result->failureCode, 'failure_message' => $result->failureMessage])->save();
            $this->history($transaction, PaymentTransactionStatus::Initiee, PaymentTransactionStatus::Echouee, $user);

            return $transaction;
        }

        return DB::transaction(function () use ($transaction, $payable, $user, $profile, $result): PaymentTransaction {
            $schedules = $payable['kind'] === 'acompte'
                ? $this->planner->confirm(Proposal::query()->lockForUpdate()->findOrFail($payable['proposal']->getKey()), $user)
                : $payable['schedules'];

            foreach ($schedules as $schedule) {
                $due = $schedule->amount_due - $schedule->amount_paid;
                $schedule->forceFill(['amount_paid' => $schedule->amount_due, 'status' => PaymentScheduleStatus::Payee])->save();
                $transaction->allocations()->create(['payment_schedule_id' => $schedule->getKey(), 'amount' => $due]);
            }

            $transaction->forceFill([
                'status' => PaymentTransactionStatus::Reussie,
                'provider_reference' => $result->providerReference,
                'completed_at' => now(),
                'payment_schedule_id' => $schedules->count() === 1 ? $schedules->first()->getKey() : null,
                'metadata' => [...$transaction->metadata, 'allocations' => $this->keys($schedules)],
            ])->save();
            $this->history($transaction, PaymentTransactionStatus::Initiee, PaymentTransactionStatus::Reussie, $user);
            $this->issueReceipt($transaction, $profile, $payable['site']);

            return $transaction->refresh();
        });
    }

    /**
     * Lignes de « Paiements » : acomptes à payer des propositions acceptées, puis échéances des missions regroupées
     * par réservation, de la plus urgente à la plus ancienne.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(User $user, ?string $siteId = null): Collection
    {
        $mine = fn (Builder $query) => $query->whereHas('promoterProfile', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->when($siteId, fn (Builder $query) => $query->whereKey($siteId));

        $deposits = Proposal::query()
            ->where('status', ProposalStatus::Acceptee)
            ->whereHas('site', $mine)
            ->whereDoesntHave('suggestions.need.missions')
            ->with(['site', 'offer'])
            ->get()
            ->map(fn (Proposal $proposal) => $this->depositRow($proposal));

        $schedules = PaymentSchedule::query()
            ->whereHas('mission.site', $mine)
            ->where('status', '!=', PaymentScheduleStatus::Annulee)
            ->with(['mission.site', 'mission.need', 'mission.assignments', 'allocations.transaction.operator', 'allocations.transaction.receipts.mediaFile'])
            ->get()
            ->groupBy(fn (PaymentSchedule $schedule) => implode('|', [$schedule->mission->need->offer_id, $schedule->schedule_type->value, $schedule->sequence, $schedule->due_date?->toDateString()]))
            ->map(fn (Collection $group) => $this->scheduleRow($group));

        $order = array_flip(array_keys(self::STATES));

        return $deposits->concat($schedules->values())
            ->sortBy([fn (array $a, array $b) => $order[$a['status']['value']] <=> $order[$b['status']['value']], fn (array $a, array $b) => strcmp((string) $a['due_date'], (string) $b['due_date'])])
            ->values();
    }

    /**
     * Ligne d’une échéance et de celles de la même réservation, de la même semaine.
     *
     * @return array<string, mixed>
     */
    public function scheduleGroup(PaymentSchedule $schedule): array
    {
        $group = PaymentSchedule::query()
            ->where('schedule_type', $schedule->schedule_type)
            ->where('sequence', $schedule->sequence)
            ->where('due_date', $schedule->due_date)
            ->whereHas('mission.need', fn (Builder $query) => $query->where('offer_id', $schedule->mission->need->offer_id))
            ->with(['mission.site', 'mission.need', 'mission.assignments', 'allocations.transaction.operator', 'allocations.transaction.receipts.mediaFile'])
            ->get();

        return $this->scheduleRow($group);
    }

    /**
     * Montant total, déjà réglé et restant de la réservation d’une échéance (« 120 000 réglés sur 672 000 »).
     *
     * @return array{total: int, paid: int, rest: int}
     */
    public function contractProgress(PaymentSchedule $schedule): array
    {
        $missions = Mission::query()->whereHas('need', fn (Builder $query) => $query->where('offer_id', $schedule->mission->need->offer_id))->with('pricingSnapshot', 'paymentSchedules')->get();
        $total = (int) $missions->sum(fn (Mission $mission) => $mission->pricingSnapshot->total_promoter_amount);
        $paid = (int) $missions->sum(fn (Mission $mission) => $mission->paymentSchedules->sum('amount_paid'));

        return ['total' => $total, 'paid' => $paid, 'rest' => max(0, $total - $paid)];
    }

    public function percent(float $value): string
    {
        return str_replace('.', ',', (string) (float) $value).' %';
    }

    public function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }

    /**
     * @return array<string, mixed>
     */
    private function deposit(User $user, string $proposalId): array
    {
        $proposal = Proposal::query()
            ->whereKey($proposalId)
            ->whereHas('site.promoterProfile', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->with(['site', 'suggestions.need.workerReservations'])
            ->first() ?? throw ValidationException::withMessages(['proposal_id' => 'Proposition introuvable.']);

        if ($proposal->status !== ProposalStatus::Acceptee) {
            throw ValidationException::withMessages(['proposal_id' => $proposal->status === ProposalStatus::AValider
                ? 'Acceptez d’abord la proposition.'
                : 'Cette proposition ne peut plus être payée.']);
        }

        if ($proposal->suggestions->contains(fn ($suggestion) => $suggestion->need->missions()->exists())) {
            throw ValidationException::withMessages(['proposal_id' => 'L’acompte de cette proposition est déjà payé.']);
        }

        $expired = $proposal->suggestions->pluck('need')->unique('id')->flatMap->workerReservations
            ->contains(fn ($reservation) => $reservation->status !== WorkerReservationStatus::Active || $reservation->expires_at->isPast());

        if ($expired) {
            throw ValidationException::withMessages(['proposal_id' => 'Le délai de paiement de l’acompte est dépassé : les ouvriers ne sont plus réservés. Une nouvelle proposition vous sera faite.']);
        }

        $count = $proposal->proposed_count;

        return [
            'kind' => 'acompte',
            'label' => 'Acompte '.$this->percent($this->pricing->value(PricingVariable::X)).' · '.$proposal->site->label,
            'amount' => $proposal->deposit_amount,
            'site' => $proposal->site,
            'proposal' => $proposal,
            'schedules' => collect(),
            'lines' => [
                ...($proposal->is_partial ? [['label' => 'Ouvriers trouvés · '.$count.' sur '.$proposal->requested_count.' demandés', 'amount' => 0]] : []),
                ['label' => 'Coût journalier des ouvriers · '.$count.' ouvrier'.($count > 1 ? 's' : ''), 'amount' => $proposal->daily_amount],
                ['label' => 'Coût des ouvriers ('.$proposal->days.' jours)', 'amount' => $proposal->workers_amount],
                ['label' => 'Commission O’CHANTIER '.$this->percent($this->pricing->value(PricingVariable::Y)), 'amount' => $proposal->commission_amount],
                ['label' => 'Total de la réservation', 'amount' => $proposal->total_amount],
                ['label' => 'Acompte '.$this->percent($this->pricing->value(PricingVariable::X)), 'amount' => $proposal->deposit_amount],
            ],
            'done_text' => 'Équipe confirmée. Démarrage prévu le '.$this->date(CarbonImmutable::parse($proposal->offer->start_date)).'.',
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function installments(User $user, array $ids): array
    {
        $schedules = PaymentSchedule::query()
            ->whereKey($ids)
            ->whereHas('mission.site.promoterProfile', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->with(['mission.site', 'mission.need'])
            ->get();

        if ($schedules->count() !== count(array_unique($ids)) || $schedules->isEmpty()) {
            throw ValidationException::withMessages(['schedule_ids' => 'Échéance introuvable.']);
        }

        if ($schedules->contains(fn (PaymentSchedule $schedule) => $schedule->status === PaymentScheduleStatus::Payee)) {
            throw ValidationException::withMessages(['schedule_ids' => 'Cette échéance est déjà payée.']);
        }

        if ($schedules->pluck('mission.need.offer_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['schedule_ids' => 'Payez les échéances d’une réservation à la fois.']);
        }

        $first = $schedules->first();
        $row = $this->scheduleRow($schedules);

        return [
            'kind' => 'echeance',
            'label' => $row['title'],
            'amount' => (int) $schedules->sum(fn (PaymentSchedule $schedule) => $schedule->amount_due - $schedule->amount_paid),
            'site' => $first->mission->site,
            'proposal' => null,
            'schedules' => $schedules,
            'lines' => [
                ['label' => 'Coût des ouvriers', 'amount' => (int) $schedules->sum('workers_amount')],
                ['label' => 'Commission O’CHANTIER '.$this->percent($this->pricing->value(PricingVariable::Y)), 'amount' => (int) $schedules->sum('promoter_commission_amount')],
                ['label' => 'Part de l’acompte déduite', 'amount' => -(int) $schedules->sum('deposit_deduction_amount')],
                ['label' => 'Montant à régler', 'amount' => (int) $schedules->sum(fn (PaymentSchedule $schedule) => $schedule->amount_due - $schedule->amount_paid)],
            ],
            'done_text' => $row['short'].' du '.$this->date(CarbonImmutable::parse($first->due_date)).' réglée.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function depositRow(Proposal $proposal): array
    {
        $count = $proposal->proposed_count;

        return [
            'key' => 'proposal:'.$proposal->id,
            'target' => ['proposal_id' => $proposal->id],
            'kind' => ['value' => 'acompte', 'label' => 'Acompte'],
            'short' => 'Acompte ('.$this->percent($this->pricing->value(PricingVariable::X)).')',
            'title' => 'Acompte · '.$proposal->site->label,
            'site' => ['id' => $proposal->site->id, 'title' => $proposal->site->label],
            'reservation_id' => $proposal->offer_id,
            'who' => $count.' ouvrier'.($count > 1 ? 's' : ''),
            'amount' => $proposal->deposit_amount,
            'status' => ['value' => 'a_payer', 'label' => self::STATES['a_payer']],
            'due_date' => null,
            'when' => 'À régler pour valider l’offre',
            'contract_amount' => $proposal->total_amount,
            'paid_at' => null,
            'method' => null,
            'transaction_reference' => null,
            'receipt' => null,
            'can_pay' => true,
        ];
    }

    /**
     * @param  Collection<int, PaymentSchedule>  $group
     * @return array<string, mixed>
     */
    private function scheduleRow(Collection $group): array
    {
        /** @var PaymentSchedule $first */
        $first = $group->sortBy('reference')->first();
        $site = $first->mission->site;
        $paid = $group->every(fn (PaymentSchedule $schedule) => $schedule->status === PaymentScheduleStatus::Payee);
        $transaction = $group->flatMap->allocations->pluck('transaction')->filter(fn ($t) => $t?->status === PaymentTransactionStatus::Reussie)->sortByDesc('completed_at')->first();
        $receipt = $transaction?->receipts->first();
        $today = CarbonImmutable::today();
        $due = $first->due_date ? CarbonImmutable::parse($first->due_date) : null;
        $workers = (int) $group->sum(fn (PaymentSchedule $schedule) => $schedule->mission->assignments->count());
        $deposit = $first->schedule_type === PaymentScheduleType::Acompte;
        $count = PaymentSchedule::query()->where('mission_id', $first->mission_id)->where('schedule_type', $first->schedule_type)->count();
        $short = $deposit ? 'Acompte ('.$this->percent($this->pricing->value(PricingVariable::X)).')' : 'Échéance '.$first->sequence.'/'.$count;

        $state = match (true) {
            $paid && $group->every(fn (PaymentSchedule $schedule) => $schedule->mission->status === MissionStatus::Terminee) => 'finalise',
            $paid => 'effectue',
            $due && $due->lessThan($today) => 'en_retard',
            $first->status === PaymentScheduleStatus::Exigible || ($due && $due->equalTo($today)) => 'a_payer',
            default => 'a_venir',
        };

        return [
            'key' => 'schedule:'.$first->id,
            'target' => ['schedule_ids' => $this->keys($group)],
            'kind' => $deposit ? ['value' => 'acompte', 'label' => 'Acompte'] : ['value' => 'echeance', 'label' => 'Échéance hebdomadaire'],
            'short' => $short,
            'title' => $short.' · '.$site->label,
            'site' => ['id' => $site->id, 'title' => $site->label],
            'reservation_id' => $first->mission->need->offer_id,
            'who' => $workers.' ouvrier'.($workers > 1 ? 's' : ''),
            'amount' => (int) $group->sum('amount_due'),
            'status' => ['value' => $state, 'label' => self::STATES[$state]],
            'due_date' => $due?->toDateString(),
            'period' => $first->period_start ? ['start' => $first->period_start->toDateString(), 'end' => $first->period_end->toDateString()] : null,
            'when' => match ($state) {
                'effectue', 'finalise' => 'Payé le '.$this->date(CarbonImmutable::parse($transaction?->completed_at ?? now())),
                'en_retard' => 'En retard · échéance du '.$this->date($due),
                default => $due ? 'Échéance du '.$this->date($due) : null,
            },
            'paid_at' => $transaction?->completed_at?->toIso8601String(),
            'method' => $transaction?->operator?->name,
            'transaction_reference' => $transaction?->reference,
            'receipt' => $receipt ? ['number' => $receipt->number, 'url' => $receipt->mediaFile ? $this->media->temporaryUrl($receipt->mediaFile) : null] : null,
            'can_pay' => ! $paid,
        ];
    }

    /**
     * Reçu de paiement PDF, adressé au promoteur.
     */
    private function issueReceipt(PaymentTransaction $transaction, PromoterProfile $profile, Site $site): Receipt
    {
        $number = sprintf('REC-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('receipt_number_seq')"));
        $user = $profile->user;
        $company = $user->ownedOrganizations()->value('legal_name');
        $metadata = $transaction->metadata;

        $pdf = (new SimplePdf)
            ->title('Reçu de paiement')
            ->text('O’CHANTIER · Abidjan, Côte d’Ivoire', 10)
            ->space()
            ->row('Reçu n°', $number, true)
            ->row('Date', $this->date(CarbonImmutable::now()).' · '.now()->timezone('Africa/Abidjan')->format('H:i'))
            ->row('Payé par', trim(($company ? $company.' · ' : '').$user->first_name.' '.$user->last_name))
            ->row('Chantier', $site->label.' ('.$site->reference.')')
            ->row('Objet', $metadata['label'])
            ->heading('Détail');

        foreach ($metadata['lines'] as $line) {
            $pdf->row($line['label'], $this->money((int) $line['amount']));
        }

        $pdf->space(4)
            ->row('Total payé', $this->money($transaction->amount + $transaction->fees_amount), true)
            ->space()
            ->row('Moyen de paiement', $transaction->operator->name.' · '.$metadata['phone'])
            ->row('Référence du paiement', $transaction->reference)
            ->row('Référence opérateur', (string) $transaction->provider_reference);

        $file = $this->media->storeContent($pdf->render(), $number.'.pdf', 'application/pdf', 'recu', $user);

        return $transaction->receipts()->create([
            'number' => $number,
            'receipt_type' => ReceiptType::Recu,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->getKey(),
            'issued_to_type' => $profile->getMorphClass(),
            'issued_to_id' => $profile->getKey(),
            'amount' => $transaction->amount + $transaction->fees_amount,
            'media_file_id' => $file->getKey(),
        ]);
    }

    /**
     * Numéro enregistré pour les prochains paiements (« Se souvenir de ce numéro »).
     */
    private function rememberNumber(PromoterProfile $profile, User $user, PaymentOperator $operator, PhoneNumber $phone): PaymentMethod
    {
        $existing = $profile->paymentMethods()->where('operator_id', $operator->getKey())->where('account_number_masked', $phone->masked())->first();

        if ($existing) {
            return $existing;
        }

        $profile->paymentMethods()->update(['is_default' => false]);

        return $profile->paymentMethods()->create([
            'method_type' => PaymentMethodType::MobileMoney,
            'operator_id' => $operator->getKey(),
            'account_holder_name' => trim($user->first_name.' '.$user->last_name) ?: $phone->formatted(),
            'account_number_encrypted' => $phone->e164(),
            'account_number_masked' => $phone->masked(),
            'is_default' => true,
            'status' => PaymentMethodStatus::Actif,
            'created_by_type' => $user->getMorphClass(),
            'created_by_id' => $user->getKey(),
        ]);
    }

    private function history(PaymentTransaction $transaction, ?PaymentTransactionStatus $from, PaymentTransactionStatus $to, User $user): void
    {
        $transaction->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'source' => TransactionStatusSource::Api, 'actor_id' => $user->getKey()]);
    }

    /**
     * @param  Collection<int, PaymentSchedule>  $schedules
     * @return list<string>
     */
    private function keys(Collection $schedules): array
    {
        return $schedules->map(fn (PaymentSchedule $schedule) => $schedule->getKey())->values()->all();
    }

    private function date(CarbonImmutable $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
