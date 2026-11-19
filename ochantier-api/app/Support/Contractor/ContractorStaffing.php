<?php

namespace App\Support\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PricingVariable;
use App\Enums\StaffingRequestStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Contractor;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\Site;
use App\Models\StaffingInstallment;
use App\Models\StaffingRequest;
use App\Models\StaffingRequestLine;
use App\Models\StaffingRequestWorker;
use App\Models\User;
use App\Models\Worker;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recherche d’effectifs de l’entrepreneur (« Rechercher des ouvriers », « Réservation d’ouvriers »).
 *
 * L’entrepreneur publie ses besoins par métier (effectif, tarif journalier) pour une période comprise dans son
 * forfait. O’CHANTIER propose les ouvriers validés et disponibles du métier (ceux de la ville du chantier d’abord,
 * puis par note), sans chevauchement d’affectation : l’offre est complète ou partielle. L’entrepreneur l’accepte
 * en payant l’acompte X et les frais de service (pourcentages du coût total des ouvriers trouvés), plus les frais
 * de l’opérateur ; les ouvriers rejoignent son équipe et le solde est payé chaque semaine. Les ouvriers manquants
 * font l’objet d’une nouvelle recherche. Il peut aussi refuser l’offre ; les ouvriers sont alors libérés.
 */
class ContractorStaffing
{
    /**
     * Frais de service par défaut (% du coût total), si le paramètre staffing.service_fee_percent manque.
     */
    public const DEFAULT_SERVICE_FEE = 5;

    private const WITH = ['site', 'lines.trade', 'lines.workers.worker.photoMedia', 'lines.workers.worker.workerTrades.trade', 'installments', 'depositTransaction.operator'];

    public function __construct(
        private ContractorTeam $team,
        private ContractorPayments $payments,
        private ContractorNotifier $notifier,
        private PricingRules $pricing,
        private MediaStore $media,
    ) {}

    /**
     * « Publier la demande ».
     *
     * @param  list<array{trade_id: string, headcount: int, daily_rate: int}>  $lines
     */
    public function create(Contractor $contractor, Site $site, User $author, CarbonImmutable $start, int $days, array $lines, ?StaffingRequest $parent = null): StaffingRequest
    {
        if (! $parent) {
            $this->team->ensureCapacity($contractor, $site, (int) array_sum(array_column($lines, 'headcount')));
        }

        $end = $start->addDays($days - 1);
        [$first, $last] = $this->team->window($contractor, $site);

        if ($start->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['start_date' => 'La période ne peut pas commencer dans le passé.']);
        }

        if ($start->lt($first) || $end->gt($last)) {
            throw ValidationException::withMessages(['start_date' => 'La période doit être comprise dans les dates du chantier ('.$first->format('d/m/Y').' – '.$last->format('d/m/Y').').']);
        }

        return DB::transaction(function () use ($contractor, $site, $author, $start, $end, $days, $lines, $parent): StaffingRequest {
            $request = StaffingRequest::query()->create([
                'reference' => sprintf('EF-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('staffing_request_reference_seq')")),
                'site_id' => $site->getKey(),
                'contractor_id' => $contractor->getKey(),
                'parent_request_id' => $parent?->getKey(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'status' => StaffingRequestStatus::Recherche,
                'deposit_percent' => $this->pricing->value(PricingVariable::X),
                'service_fee_percent' => (float) (Setting::query()->whereKey('staffing.service_fee_percent')->value('value') ?? self::DEFAULT_SERVICE_FEE),
                'created_by_user_id' => $author->getKey(),
            ]);

            foreach ($lines as $line) {
                $request->lines()->create(['trade_id' => $line['trade_id'], 'headcount' => (int) $line['headcount'], 'daily_rate' => (int) $line['daily_rate']]);
            }

            return $request->load(self::WITH);
        });
    }

    /**
     * Recherche automatique : propose, pour chaque besoin, les ouvriers validés et disponibles du métier, libres sur
     * la période (ville du chantier d’abord, puis par note). $chosen (ligne => ouvriers) remplace la recherche
     * automatique (Back Office). Sans aucun ouvrier trouvé, la recherche reste ouverte.
     *
     * @param  array<string, list<string>>|null  $chosen
     */
    public function search(StaffingRequest $request, ?array $chosen = null): StaffingRequest
    {
        if ($request->status !== StaffingRequestStatus::Recherche) {
            throw ValidationException::withMessages(['staffing_request' => 'Cette recherche n’attend plus d’offre.']);
        }

        $request->loadMissing(['site.commune', 'lines']);
        $start = CarbonImmutable::parse($request->start_date);
        $end = CarbonImmutable::parse($request->end_date);
        $taken = [];

        return DB::transaction(function () use ($request, $chosen, $start, $end, &$taken): StaffingRequest {
            foreach ($request->lines as $line) {
                $line->workers()->delete();
                $workers = $chosen !== null
                    ? Worker::query()->whereKey($chosen[$line->id] ?? [])->get()
                    : $this->candidates($line, $request->site, $start, $end, $taken);

                foreach ($workers->take($line->headcount) as $worker) {
                    if ($chosen !== null && $this->team->conflict($worker, $start, $end)) {
                        throw ValidationException::withMessages(['workers' => trim($worker->first_name.' '.$worker->last_name).' est déjà affecté sur cette période.']);
                    }

                    $line->workers()->create(['worker_id' => $worker->getKey(), 'status' => 'propose']);
                    $taken[] = $worker->getKey();
                }
            }

            $found = $request->lines()->withCount('workers')->get()->sum('workers_count');

            if ($found > 0) {
                $amounts = $this->amounts($request->refresh()->load('lines.workers'));
                $request->forceFill(['status' => StaffingRequestStatus::Offre, 'offered_at' => now(), ...$amounts])->save();
                $this->notifier->notify($request->contractor, 'staffing.offer', 'Offre d’ouvriers reçue',
                    $request->site->label.' : '.$found.' ouvrier'.($found > 1 ? 's' : '').' trouvé'.($found > 1 ? 's' : '').' sur '.$request->lines->sum('headcount').' demandés.',
                    ['type' => 'staffing_request', 'id' => $request->id, 'site_id' => $request->site_id], $request);
            }

            return $request->refresh()->load(self::WITH);
        });
    }

    /**
     * Détail du paiement de l’acompte avec l’opérateur choisi.
     *
     * @return array<string, mixed>
     */
    public function depositQuote(StaffingRequest $request, PaymentOperator $operator): array
    {
        $this->ensureOffer($request);

        return $this->payments->quote($this->depositLines($request), $request->deposit_amount + $request->service_fee_amount, $operator);
    }

    /**
     * « Accepter et payer » : acompte et frais de service. Le paiement réussi affecte les ouvriers retenus à
     * l’équipe (confirmés), crée les échéances du solde et relance la recherche des ouvriers manquants.
     */
    public function payDeposit(StaffingRequest $request, User $user, PaymentOperator $operator, PhoneNumber $phone, string $idempotencyKey): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $this->ensureOffer($request);
        $request->loadMissing(['contractor', 'site', 'lines.workers.worker.workerTrades']);

        foreach ($request->lines as $line) {
            foreach ($line->workers as $proposed) {
                if ($this->team->conflict($proposed->worker, CarbonImmutable::parse($request->start_date), CarbonImmutable::parse($request->end_date))) {
                    throw ValidationException::withMessages(['staffing_request' => trim($proposed->worker->first_name.' '.$proposed->worker->last_name).' n’est plus disponible : une nouvelle offre est nécessaire.']);
                }
            }
        }

        $transaction = $this->payments->pay($request->contractor, $user, 'Acompte '.$request->reference.' · '.$request->site->label, $this->depositLines($request),
            $request->deposit_amount + $request->service_fee_amount, $operator, $phone, $idempotencyKey,
            ['staffing_request_id' => $request->id, 'kind' => 'acompte', 'site_id' => $request->site_id],
            fn (PaymentTransaction $transaction) => $this->confirm($request, $user, $transaction));

        if ($transaction->status === PaymentTransactionStatus::Reussie) {
            $this->notifier->notify($request->contractor, 'staffing.paid', 'Paiement confirmé',
                $request->site->label.' : '.$this->found($request).' ouvriers réservés, démarrage le '.$request->start_date->format('d/m/Y').'.',
                ['type' => 'staffing_request', 'id' => $request->id, 'site_id' => $request->site_id], $request);
        }

        return $transaction;
    }

    /**
     * Paie une échéance du solde.
     */
    public function payInstallment(StaffingInstallment $installment, User $user, PaymentOperator $operator, PhoneNumber $phone, string $idempotencyKey): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        if ($installment->status !== 'a_payer') {
            throw ValidationException::withMessages(['installment' => 'Cette échéance est déjà payée.']);
        }

        $request = $installment->staffingRequest()->with(['contractor', 'site'])->firstOrFail();

        return $this->payments->pay($request->contractor, $user, $installment->label.' · '.$request->reference, [['label' => $installment->label, 'amount' => $installment->amount]],
            $installment->amount, $operator, $phone, $idempotencyKey,
            ['staffing_request_id' => $request->id, 'staffing_installment_id' => $installment->id, 'kind' => 'echeance', 'site_id' => $request->site_id],
            fn (PaymentTransaction $transaction) => $installment->forceFill(['status' => 'payee', 'paid_at' => now(), 'payment_transaction_id' => $transaction->getKey()])->save());
    }

    public function installmentQuote(StaffingInstallment $installment, PaymentOperator $operator): array
    {
        return $this->payments->quote([['label' => $installment->label, 'amount' => $installment->amount]], $installment->amount, $operator);
    }

    /**
     * « Refuser l’offre » : les ouvriers proposés sont libérés.
     */
    public function refuse(StaffingRequest $request): StaffingRequest
    {
        $this->ensureOffer($request);

        DB::transaction(function () use ($request): void {
            StaffingRequestWorker::query()->whereIn('staffing_request_line_id', $request->lines()->pluck('id'))->update(['status' => 'libere']);
            $request->forceFill(['status' => StaffingRequestStatus::Refusee, 'decided_at' => now()])->save();
        });

        return $request->refresh()->load(self::WITH);
    }

    /**
     * Annule une recherche qui n’a pas encore d’offre.
     */
    public function cancel(StaffingRequest $request): StaffingRequest
    {
        if ($request->status !== StaffingRequestStatus::Recherche) {
            throw ValidationException::withMessages(['staffing_request' => 'Seule une recherche sans offre peut être annulée : refusez plutôt l’offre.']);
        }

        $request->forceFill(['status' => StaffingRequestStatus::Annulee, 'decided_at' => now()])->save();

        return $request->refresh()->load(self::WITH);
    }

    public function find(Contractor $contractor, Site $site, string $id): StaffingRequest
    {
        $request = str($id)->isUuid() ? StaffingRequest::query()->with(self::WITH)->where('contractor_id', $contractor->getKey())->where('site_id', $site->getKey())->find($id) : null;

        abort_unless($request, 404, 'Recherche d’effectifs introuvable.');

        return $request;
    }

    /**
     * Recherches d’effectifs du chantier, les plus récentes d’abord.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(Contractor $contractor, Site $site): Collection
    {
        return StaffingRequest::query()->with(self::WITH)->where('contractor_id', $contractor->getKey())->where('site_id', $site->getKey())->latest()->get()
            ->map(fn (StaffingRequest $request) => $this->card($request));
    }

    /**
     * Échéances du solde à payer par l’entrepreneur, sur tous ses chantiers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dueInstallments(Contractor $contractor): Collection
    {
        return StaffingInstallment::query()->with('staffingRequest.site')
            ->where('status', 'a_payer')
            ->whereHas('staffingRequest', fn (Builder $query) => $query->where('contractor_id', $contractor->getKey()))
            ->orderBy('due_date')
            ->get()
            ->map(fn (StaffingInstallment $installment) => [
                ...$this->installment($installment),
                'staffing_request' => ['id' => $installment->staffing_request_id, 'reference' => $installment->staffingRequest->reference],
                'site' => ['id' => $installment->staffingRequest->site_id, 'title' => $installment->staffingRequest->site->label],
            ]);
    }

    public function findInstallment(Contractor $contractor, string $id): StaffingInstallment
    {
        $installment = str($id)->isUuid()
            ? StaffingInstallment::query()->whereKey($id)->whereHas('staffingRequest', fn (Builder $query) => $query->where('contractor_id', $contractor->getKey()))->first()
            : null;

        abort_unless($installment, 404, 'Échéance introuvable.');

        return $installment;
    }

    /**
     * Carte d’une recherche (liste du chantier).
     *
     * @return array<string, mixed>
     */
    public function card(StaffingRequest $request): array
    {
        $wanted = (int) $request->lines->sum('headcount');
        $found = $this->found($request);

        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'status' => ['value' => $request->status->value, 'label' => $request->status->label()],
            'summary' => $request->lines->map(fn (StaffingRequestLine $line) => $line->headcount.' × '.$line->trade->label)->implode(', '),
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'days' => $request->days,
            'wanted' => $wanted,
            'found' => $found,
            'progress_label' => match ($request->status) {
                StaffingRequestStatus::Recherche => 'Recherche…',
                StaffingRequestStatus::Offre => 'Offre '.$found.'/'.$wanted,
                default => $request->status->label(),
            },
            'parent_request_id' => $request->parent_request_id,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * Écran « Réservation d’ouvriers » : bandeau (recherche, offre complète ou partielle, confirmée), ouvriers
     * trouvés et manquants, conditions, paiement, échéances et suivi.
     *
     * @return array<string, mixed>
     */
    public function detail(StaffingRequest $request): array
    {
        $card = $this->card($request);
        $searching = $request->status === StaffingRequestStatus::Recherche;
        $partial = $card['found'] < $card['wanted'];
        $estimate = $searching ? (int) $request->lines->sum(fn (StaffingRequestLine $line) => $line->headcount * $line->daily_rate) * $request->days : $request->workers_amount;
        $step = match ($request->status) {
            StaffingRequestStatus::Recherche => 1,
            StaffingRequestStatus::Offre, StaffingRequestStatus::Refusee => 2,
            StaffingRequestStatus::Payee => 4,
            StaffingRequestStatus::Annulee => 1,
        };

        return [
            ...$card,
            'banner' => match (true) {
                $searching => ['title' => 'Recherche d’ouvriers…', 'text' => 'Nous contactons les profils disponibles correspondant à vos besoins. Vous serez notifié dès qu’une offre est prête.'],
                $request->status === StaffingRequestStatus::Payee => ['title' => 'Réservation validée', 'text' => 'Les ouvriers retenus ont rejoint votre équipe.'],
                $partial => ['title' => 'Proposition partielle', 'text' => 'Facturé uniquement sur les ouvriers trouvés. La recherche continue pour les ouvriers manquants après votre validation.'],
                default => ['title' => 'Proposition complète', 'text' => 'Tous les ouvriers demandés ont été trouvés.'],
            },
            'lines' => $request->lines->map(fn (StaffingRequestLine $line) => [
                'id' => $line->id,
                'trade' => $line->trade->only(['id', 'code', 'label']),
                'headcount' => $line->headcount,
                'daily_rate' => $line->daily_rate,
                'found' => $line->workers->where('status', '!=', 'libere')->count(),
                'missing' => max(0, $line->headcount - $line->workers->where('status', '!=', 'libere')->count()),
                'workers' => $line->workers->map(fn (StaffingRequestWorker $proposed) => [
                    'id' => $proposed->worker_id,
                    'name' => trim($proposed->worker->first_name.' '.$proposed->worker->last_name),
                    'initials' => mb_strtoupper(mb_substr((string) $proposed->worker->first_name, 0, 1).mb_substr((string) $proposed->worker->last_name, 0, 1)),
                    'photo_url' => $proposed->worker->photoMedia ? $this->media->temporaryUrl($proposed->worker->photoMedia) : null,
                    'rating' => $proposed->worker->rating_avg !== null ? (float) $proposed->worker->rating_avg : null,
                    'status' => $proposed->status,
                    'assignment_id' => $proposed->assignment_id,
                ])->values()->all(),
            ])->values()->all(),
            'conditions' => [
                'daily_cost' => $searching ? (int) $request->lines->sum(fn (StaffingRequestLine $line) => $line->headcount * $line->daily_rate) : (int) round($request->workers_amount / max(1, $request->days)),
                'total' => $estimate,
                'deposit_percent' => $request->deposit_percent,
                'deposit_amount' => $searching ? $this->pricing->deposit($estimate) : $request->deposit_amount,
                'balance_amount' => $estimate - ($searching ? $this->pricing->deposit($estimate) : $request->deposit_amount),
                'service_fee_percent' => $request->service_fee_percent,
                'service_fee_amount' => $searching ? (int) round($estimate * $request->service_fee_percent / 100) : $request->service_fee_amount,
                'estimate' => $searching,
                'calculation' => ($searching ? $card['wanted'] : $card['found']).' ouvrier(s) × '.$request->days.' jours × tarif journalier · acompte '.$this->percent($request->deposit_percent).($searching ? ' (estimation)' : ''),
            ],
            'payment' => $request->depositTransaction ? [
                'reference' => $request->depositTransaction->reference,
                'amount' => $request->depositTransaction->amount + $request->depositTransaction->fees_amount,
                'deposit' => $request->deposit_amount,
                'service_fee' => $request->service_fee_amount,
                'operator_fee' => $request->depositTransaction->fees_amount,
                'operator' => $request->depositTransaction->operator?->name,
                'phone' => $request->depositTransaction->metadata['phone'] ?? null,
                'paid_at' => $request->paid_at?->toIso8601String(),
            ] : null,
            'installments' => $request->installments->sortBy('sequence')->map(fn (StaffingInstallment $installment) => $this->installment($installment))->values()->all(),
            'tracking' => collect([['published', 'Demande publiée', 'Besoins transmis aux ouvriers'], ['search', 'Recherche d’ouvriers', 'Contact des profils disponibles'], ['offer', 'Offre reçue', 'Acceptez ou refusez l’offre'], ['deposit', 'Acompte et confirmation', 'Réservation validée après paiement']])
                ->map(fn (array $item, int $index) => ['key' => $item[0], 'label' => $item[1], 'sub' => $item[2], 'state' => match (true) {
                    $request->status === StaffingRequestStatus::Payee || $index < $step => 'done',
                    $index === $step && $request->status === StaffingRequestStatus::Refusee => 'failed',
                    $index === $step => 'current',
                    default => 'todo',
                }])->all(),
            'actions' => [
                'accept_and_pay' => $request->status === StaffingRequestStatus::Offre,
                'refuse' => $request->status === StaffingRequestStatus::Offre,
                'cancel' => $searching,
            ],
        ];
    }

    /**
     * Acompte payé : ouvriers affectés (confirmés), échéances du solde, nouvelle recherche des manquants.
     */
    private function confirm(StaffingRequest $request, User $user, PaymentTransaction $transaction): void
    {
        $request = StaffingRequest::query()->lockForUpdate()->with(['contractor', 'site', 'lines.trade', 'lines.workers.worker.workerTrades'])->findOrFail($request->getKey());

        if ($request->status !== StaffingRequestStatus::Offre) {
            throw ValidationException::withMessages(['staffing_request' => 'Cette offre n’est plus en attente de paiement.']);
        }

        $start = CarbonImmutable::parse($request->start_date);
        $end = CarbonImmutable::parse($request->end_date);
        $missing = [];

        foreach ($request->lines as $line) {
            foreach ($line->workers as $proposed) {
                $assignment = $this->team->createAssignment($request->contractor, $request->site, $user, $proposed->worker, $line->trade->label, $line->daily_rate, $start, $end,
                    AssignmentStatus::Confirmee, trim($proposed->worker->first_name.' '.$proposed->worker->last_name).' réservé par '.$request->contractor->name.' ('.$request->reference.').');
                $proposed->forceFill(['status' => 'retenu', 'assignment_id' => $assignment->getKey()])->save();
            }

            if ($line->workers->count() < $line->headcount) {
                $missing[] = ['trade_id' => $line->trade_id, 'headcount' => $line->headcount - $line->workers->count(), 'daily_rate' => $line->daily_rate];
            }
        }

        $this->createInstallments($request, $start, $end);
        $request->forceFill(['status' => StaffingRequestStatus::Payee, 'decided_at' => now(), 'paid_at' => now(), 'deposit_transaction_id' => $transaction->getKey()])->save();

        if ($missing !== [] && $start->gte(CarbonImmutable::today())) {
            $this->create($request->contractor, $request->site, $user, $start, $request->days, $missing, $request);
        }
    }

    /**
     * Solde (coût total moins l’acompte) en échéances hebdomadaires, à la fin de chaque semaine de travail.
     */
    private function createInstallments(StaffingRequest $request, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $balance = $request->workers_amount - $request->deposit_amount;
        $weeks = max(1, (int) ceil($request->days / 7));

        for ($k = 1; $k <= $weeks; $k++) {
            $amount = $k === $weeks ? $balance - (int) floor($balance / $weeks) * ($weeks - 1) : (int) floor($balance / $weeks);
            $request->installments()->create([
                'sequence' => $k,
                'label' => 'Semaine '.$k.'/'.$weeks,
                'due_date' => $start->addDays(min($request->days, 7 * $k) - 1)->min($end)->toDateString(),
                'amount' => $amount,
                'status' => 'a_payer',
            ]);
        }
    }

    /**
     * Ouvriers validés et disponibles du métier, libres sur la période : ville du chantier d’abord, puis par note.
     *
     * @param  list<string>  $taken
     * @return Collection<int, Worker>
     */
    private function candidates(StaffingRequestLine $line, Site $site, CarbonImmutable $start, CarbonImmutable $end, array $taken): Collection
    {
        $city = $site->commune?->city_id;

        return Worker::query()->with('residenceCommune')
            ->where('verification_status', WorkerVerificationStatus::Valide->value)
            ->where('availability_status', AvailabilityStatus::Disponible->value)
            ->whereNull('merged_into_worker_id')
            ->whereKeyNot($taken)
            ->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $line->trade_id))
            ->whereDoesntHave('assignments', fn (Builder $query) => $query
                ->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, ContractorTeam::LIVE))
                ->where('planned_start_date', '<=', $end->toDateString())
                ->where('planned_end_date', '>=', $start->toDateString()))
            ->get()
            ->sortBy(fn (Worker $worker) => [$worker->residenceCommune?->city_id === $city ? 0 : 1, -((float) $worker->rating_avg)])
            ->values();
    }

    /**
     * @return array{workers_amount: int, deposit_amount: int, service_fee_amount: int}
     */
    private function amounts(StaffingRequest $request): array
    {
        $total = (int) $request->lines->sum(fn (StaffingRequestLine $line) => $line->workers->count() * $line->daily_rate * $request->days);

        return [
            'workers_amount' => $total,
            'deposit_amount' => $this->pricing->deposit($total),
            'service_fee_amount' => (int) round($total * $request->service_fee_percent / 100),
        ];
    }

    /**
     * @return list<array{label: string, amount: int}>
     */
    private function depositLines(StaffingRequest $request): array
    {
        return [
            ['label' => 'Coût total des ouvriers ('.$request->days.' jours)', 'amount' => $request->workers_amount],
            ['label' => 'Acompte '.$this->percent($request->deposit_percent), 'amount' => $request->deposit_amount],
            ['label' => 'Frais de service '.$this->percent($request->service_fee_percent).' du coût total', 'amount' => $request->service_fee_amount],
        ];
    }

    private function found(StaffingRequest $request): int
    {
        return (int) $request->lines->sum(fn (StaffingRequestLine $line) => $line->workers->where('status', '!=', 'libere')->count());
    }

    private function ensureOffer(StaffingRequest $request): void
    {
        if ($request->status !== StaffingRequestStatus::Offre) {
            throw ValidationException::withMessages(['staffing_request' => $request->status === StaffingRequestStatus::Payee ? 'Cette réservation est déjà payée.' : 'Aucune offre n’est en attente pour cette recherche.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function installment(StaffingInstallment $installment): array
    {
        $late = $installment->status === 'a_payer' && $installment->due_date->lt(today());

        return [
            'id' => $installment->id,
            'sequence' => $installment->sequence,
            'label' => $installment->label,
            'due_date' => $installment->due_date->toDateString(),
            'amount' => $installment->amount,
            'state' => match (true) {
                $installment->status === 'payee' => ['value' => 'paye', 'label' => 'Payé'],
                $late => ['value' => 'en_retard', 'label' => 'En retard'],
                default => ['value' => 'a_payer', 'label' => 'À payer'],
            },
            'paid_at' => $installment->paid_at?->toIso8601String(),
        ];
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',').' %';
    }
}
