<?php

namespace App\Support\Admin;

use App\Enums\FinancialPeriodClosingStatus as Status;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReconciliationItemStatus;
use App\Models\Admin;
use App\Models\FinancialPeriodClosing;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture mensuelle : une personne prépare, une autre clôture, ce qui fige les chiffres du mois. Elle ne bloque aucune
 * opération : si une opération tardive change un mois clos, l’écart avec la photo figée reste affiché.
 */
class FinancialClosings
{
    private const HISTORY = 12;

    public function __construct(private FinanceReport $report) {}

    /**
     * Les derniers mois terminés, clôturés ou non.
     *
     * @return list<array<string, mixed>>
     */
    public function months(): array
    {
        $first = CarbonImmutable::today()->startOfMonth();
        $rows = FinancialPeriodClosing::query()->with('closedByAdmin')->get()->keyBy(fn (FinancialPeriodClosing $closing) => $closing->period_month->format('Y-m'));

        return collect(range(1, self::HISTORY))
            ->map(fn (int $back) => $first->subMonthsNoOverflow($back)->format('Y-m'))
            ->merge($rows->keys())
            ->unique()
            ->sortDesc()
            ->map(fn (string $month) => $this->summary($month, $rows->get($month)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function month(string $month): array
    {
        $closing = $this->find($month);
        $live = $this->figures($month);
        $snapshot = $closing?->snapshot;
        $frozen = $closing?->status === Status::Cloturee;

        return [
            ...$this->summary($month, $closing),
            'figures' => $frozen ? $snapshot['figures'] : $live,
            'prepared' => $snapshot['figures'] ?? null,
            'warnings' => $this->warnings($month),
            'drift' => $frozen ? $this->drift($snapshot['figures'], $live) : [],
            'can' => $this->actions($month, $closing),
        ];
    }

    public function prepare(string $month, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($month, $admin, $request): void {
            $closing = $this->lock($month);
            $this->ensure($this->actions($month, $closing)['prepare'], 'Ce mois ne peut pas être préparé.');

            $closing->fill(['status' => Status::EnCloture, 'snapshot' => [
                'figures' => $this->figures($month),
                'warnings' => $this->warnings($month),
                'prepared_by' => ['id' => $admin->getKey(), 'name' => $admin->fullName()],
                'prepared_at' => now()->toIso8601String(),
            ]])->save();
            AdminAudit::record($admin, 'finance', 'finance.closing_prepared', $request, $closing, null, ['month' => $month]);
        });
    }

    /**
     * La personne qui clôture valide ce qui a été préparé : si les chiffres ont bougé, il faut préparer de nouveau.
     */
    public function close(string $month, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($month, $admin, $request): void {
            $closing = $this->lock($month);
            $this->ensure($closing->status === Status::EnCloture, 'Ce mois n’est pas en clôture.');
            $this->ensure(($closing->snapshot['prepared_by']['id'] ?? null) !== $admin->getKey(), 'Une autre personne doit clôturer ce mois.');
            $this->ensure($this->drift($closing->snapshot['figures'], $this->figures($month)) === [], 'Les chiffres ont changé depuis la préparation : préparez de nouveau ce mois.');

            $closing->update(['status' => Status::Cloturee, 'version' => $closing->version + 1, 'closed_by_admin_id' => $admin->getKey(), 'closed_at' => now()]);
            AdminAudit::record($admin, 'finance', 'finance.closing_closed', $request, $closing, null, ['month' => $month, 'version' => $closing->version, 'figures' => $closing->snapshot['figures']]);
        });
    }

    public function reopen(string $month, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($month, $reason, $admin, $request): void {
            $closing = $this->lock($month);
            $this->ensure($closing->status === Status::Cloturee, 'Ce mois n’est pas clôturé.');

            $closing->update(['status' => Status::Reouverte, 'reopened_by_admin_id' => $admin->getKey(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
            AdminAudit::record($admin, 'finance', 'finance.closing_reopened', $request, $closing, ['figures' => $closing->snapshot['figures']], ['month' => $month, 'reason' => $reason]);
        });
    }

    /**
     * Photo du mois à exporter : la version figée si le mois est clôturé.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public function lines(string $month): array
    {
        $data = $this->month($month);
        $f = $data['figures'];

        return [
            ['Mois', $month],
            ['Statut', $data['status']['label']],
            ['Version', $data['version']],
            ['Paiements des missions', $f['inflows']['missions']],
            ['Abonnements', $f['inflows']['subscriptions']],
            ['Total reçu', $f['inflows']['total']],
            ['Frais d’opérateur', $f['inflows']['operator_fees']],
            ['Reversements aux ouvriers', $f['outflows']['workers']],
            ['Versements aux commerciaux', $f['outflows']['commercials']],
            ['Remboursements', $f['outflows']['refunds']],
            ['Total décaissé', $f['outflows']['total']],
            ['Solde de trésorerie', $f['net_cash']],
            ['Commission promoteur (Y)', $f['commissions']['promoter_earned']],
            ['Commission ouvrier (Z)', $f['commissions']['worker_earned']],
            ['Commissions acquises', $f['commissions']['earned']],
            ['Commissions encaissées', $f['commissions']['collected']],
            ['Revenu', $f['revenue']],
        ];
    }

    /**
     * @return array{prepare: bool, close: bool, reopen: bool}
     */
    public function actions(string $month, ?FinancialPeriodClosing $closing): array
    {
        $status = $closing?->status ?? Status::Ouverte;
        $finished = $month < CarbonImmutable::today()->format('Y-m');

        return ['prepare' => $finished && $status !== Status::Cloturee, 'close' => $status === Status::EnCloture, 'reopen' => $status === Status::Cloturee];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $month, ?FinancialPeriodClosing $closing): array
    {
        $status = $closing?->status ?? Status::Ouverte;

        return [
            'month' => $month,
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'version' => $closing?->version ?? 0,
            'revenue' => $closing?->snapshot['figures']['revenue'] ?? null,
            'prepared_by' => $closing?->snapshot['prepared_by']['name'] ?? null,
            'prepared_by_id' => $closing?->snapshot['prepared_by']['id'] ?? null,
            'prepared_at' => $closing?->snapshot['prepared_at'] ?? null,
            'closed_by' => $closing?->closedByAdmin?->fullName(),
            'closed_at' => $closing?->closed_at?->toIso8601String(),
            'reopen_reason' => $closing?->status === Status::Reouverte ? $closing->reopen_reason : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(string $month): array
    {
        [$from, $to] = $this->bounds($month);

        return $this->report->overview($from, $to);
    }

    /**
     * Écarts à connaître avant de clôturer. Ils n’empêchent pas la clôture.
     *
     * @return list<string>
     */
    private function warnings(string $month): array
    {
        [$from, $to] = $this->bounds($month);
        $unreconciled = PaymentTransaction::query()->where('status', PaymentTransactionStatus::Reussie)->whereNull('reconciled_at')->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])->count();
        $gaps = ReconciliationItem::query()->where('status', ReconciliationItemStatus::Ouvert)->whereHas('run', fn ($query) => $query->where('period_start', '<=', $to->endOfDay())->where('period_end', '>=', $from->startOfDay()))->count();

        return array_values(array_filter([
            $unreconciled ? $unreconciled.' opération(s) du mois non rapprochée(s)' : null,
            $gaps ? $gaps.' écart(s) de rapprochement ouvert(s)' : null,
        ]));
    }

    /**
     * Différences sur les chiffres propres au mois. Les créances et le reste à recevoir évoluent chaque jour.
     *
     * @param  array<string, mixed>  $frozen
     * @param  array<string, mixed>  $live
     * @return list<array{label: string, frozen: int, live: int}>
     */
    private function drift(array $frozen, array $live): array
    {
        $rows = [
            ['Total reçu', $frozen['inflows']['total'], $live['inflows']['total']],
            ['Total décaissé', $frozen['outflows']['total'], $live['outflows']['total']],
            ['Commissions acquises', $frozen['commissions']['earned'], $live['commissions']['earned']],
            ['Commissions encaissées', $frozen['commissions']['collected'], $live['commissions']['collected']],
            ['Revenu', $frozen['revenue'], $live['revenue']],
        ];

        return collect($rows)
            ->filter(fn (array $row) => $row[1] !== $row[2])
            ->map(fn (array $row) => ['label' => $row[0], 'frozen' => $row[1], 'live' => $row[2]])
            ->values()
            ->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$from, $from->endOfMonth()];
    }

    private function find(string $month): ?FinancialPeriodClosing
    {
        return FinancialPeriodClosing::query()->with('closedByAdmin')->whereDate('period_month', $month.'-01')->first();
    }

    private function lock(string $month): FinancialPeriodClosing
    {
        return FinancialPeriodClosing::query()->whereDate('period_month', $month.'-01')->lockForUpdate()->first()
            ?? new FinancialPeriodClosing(['period_month' => $month.'-01', 'status' => Status::Ouverte]);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['closing' => $message]);
        }
    }
}
