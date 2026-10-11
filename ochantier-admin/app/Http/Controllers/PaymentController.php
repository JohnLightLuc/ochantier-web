<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\PaymentItem;
use App\Models\PaymentNotification;
use App\Models\PaymentRefund;
use App\Models\PayoutBatch;
use App\Support\AttendanceCalculator;
use App\Support\PaymentInsights;
use App\Support\PaymentLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Paiements : encaissements promoteurs (prélèvement automatique), reversements ouvriers, commissions O’CHANTIER,
 * remboursements, contrôles et rapports.
 */
class PaymentController extends Controller
{
    public const TABS = ['' => 'Vue d’ensemble', 'calcul' => 'Montants et commission', 'paiement' => 'Paiement promoteur', 'reversement' => 'Reversement ouvrier', 'remboursements' => 'Remboursements', 'pointages' => 'Pointages', 'documents' => 'Documents', 'historique' => 'Historique'];

    /**
     * Onglets accessibles par un autre nom (liens « commission », « bonus », « rapprochement »).
     *
     * @var array<string, string>
     */
    public const TAB_ALIASES = ['commission' => 'calcul', 'bonus' => 'calcul', 'rapprochement' => 'paiement'];

    /**
     * Ventilations des commissions : clé → libellé.
     *
     * @var array<string, string>
     */
    public const DIMENSIONS = ['jour' => 'Jour', 'semaine' => 'Semaine', 'mois' => 'Mois', 'annee' => 'Année', 'promoteur' => 'Promoteur', 'chantier' => 'Chantier', 'metier' => 'Métier', 'type' => 'Type d’opération', 'moyen' => 'Moyen de paiement', 'prestataire' => 'Prestataire'];

    /**
     * Contexte commun : registre, présentation, période, filtres et opérations de la période.
     *
     * @return array{0: PaymentInsights, 1: array<string, mixed>, 2: array<string, mixed>, 3: Collection<int, Payment>}
     */
    private function context(Request $request): array
    {
        $insights = new PaymentInsights(new PaymentLedger);
        $filters = $request->only(['periode', 'du', 'au', 'q', 'raccourci', 'tri', 'page', 'plus', 'ventilation', 'niveau', 'statut', ...array_keys(PaymentInsights::FILTERS)]);
        $period = PaymentInsights::period($filters['periode'] ?? null, $filters['du'] ?? null, $filters['au'] ?? null);

        return [$insights, $filters, $period, $insights->inPeriod($period['from'], $period['to'])];
    }

    /**
     * @return array<string, mixed>
     */
    private function common(PaymentInsights $insights, string $path, array $filters, array $period): array
    {
        return ['sections' => $insights->sections($path), 'period' => PaymentInsights::periodMeta($period), 'periods' => PaymentInsights::PERIODS, 'filters' => array_filter($filters), 'options' => self::options()];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function options(): array
    {
        return [
            'refundTypes' => PaymentActionController::REFUND_TYPES,
            'responsibilities' => PaymentActionController::RESPONSIBILITIES,
            'reconcileMotifs' => PaymentActionController::RECONCILE_MOTIFS,
            'anomalyTypes' => PaymentActionController::ANOMALY_TYPES,
            'levels' => ['Information', 'Faible', 'Modérée', 'Élevée', 'Critique'],
            'bonusTypes' => PaymentActionController::ITEM_TYPES['bonus'],
            'indemnityTypes' => PaymentActionController::ITEM_TYPES['indemnity'],
            'deductionTypes' => PaymentActionController::ITEM_TYPES['deduction'],
        ];
    }

    /**
     * Liste d’opérations (recherche, filtres, tri, pagination et totaux).
     *
     * @param  Collection<int, Payment>  $base
     * @return array<string, mixed>
     */
    private function list(PaymentInsights $insights, Collection $base, array $filters): array
    {
        $more = (bool) ($filters['plus'] ?? false);
        $rows = $insights->sort($insights->filter($base, $filters), $filters['tri'] ?? 'date');

        return [
            'filterDefs' => $insights->filterOptions($base, $more ? array_keys(PaymentInsights::FILTERS) : PaymentInsights::BASIC_FILTERS),
            'filterCount' => count(PaymentInsights::FILTERS),
            'sorts' => PaymentInsights::SORTS,
            'payments' => PaymentInsights::paginate($rows, (int) ($filters['page'] ?? 1), 8, fn (Payment $p): array => $insights->row($p)),
            'totals' => $insights->totals($rows),
            'hasData' => $insights->ledger->all()->isNotEmpty(),
        ];
    }

    /**
     * Vue d’ensemble : commissions nettes, encaissements, reversements, répartition, travail à faire et opérations.
     */
    public function index(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        $scoped = $insights->filter($items, [...$filters, 'raccourci' => null]);
        $previous = $insights->inPeriod($period['previousFrom'], $period['previousTo']);
        $net = $insights->sum($scoped, 'comn');
        $before = $insights->sum($previous, 'comn');
        $collected = $scoped->filter(fn (Payment $p): bool => $ledger->calc($p)['comCollected'] > 0);
        $count = fn (string $k): int => $scoped->filter(fn (Payment $p): bool => $insights->shortcutValue($k, $p) > 0)->count();
        $row = fn (string $k, string $label, string $color): array => ['key' => $k, 'label' => $label, 'value' => $insights->sum($scoped, $k), 'count' => $count($k), 'color' => $color];
        $paid = $scoped->filter(fn (Payment $p): bool => $ledger->calc($p)['paid']);
        $paidSum = fn (callable $f): int => (int) $paid->sum(fn (Payment $p): int => $f($ledger->calc($p), $p));
        $cfg = PaymentLedger::config();

        return Inertia::render('Payments/Index', [
            ...$this->common($insights, '/paiements', $filters, $period),
            ...$this->list($insights, $items, $filters),
            'kpis' => [
                ['key' => null, 'label' => 'Total des commissions O’CHANTIER', 'value' => $net, 'icon' => 'account_balance', 'tone' => 'g', 'highlight' => true, 'hint' => ($net >= $before ? '+' : '').($before ? (int) round(($net - $before) / $before * 100) : 0).' % vs période précédente · '.$collected->count().' op. · moy. '.PaymentLedger::money($collected->count() ? (int) round($net / $collected->count()) : 0).' · Voir le détail', 'href' => '/paiements/commissions'],
                ...collect([['recu', 'g'], ['arev', 'a'], ['att', 'b']])->map(fn (array $k): array => ['key' => $k[0], 'label' => PaymentInsights::SHORTCUTS[$k[0]][0], 'value' => $insights->sum($scoped, $k[0]), 'icon' => PaymentInsights::SHORTCUTS[$k[0]][1], 'tone' => $k[1], 'hint' => $count($k[0]).' opération(s)'])->all(),
            ],
            'groups' => [
                ['title' => 'Encaissements promoteurs', 'sub' => 'Prélèvement automatique · prestation + commission + frais', 'icon' => 'payments', 'tone' => 'info', 'total' => $insights->sum($scoped, 'recu'), 'totalLabel' => 'reçus', 'rows' => [$row('att', 'Attendus', 'var(--c-info)'), $row('pend', 'En cours', 'var(--c-warning-fg)'), $row('recu', 'Reçus', 'var(--c-success-fg)'), $row('ech', 'Échoués', 'var(--c-danger)'), $row('rmb', 'Remboursés', 'var(--c-accent-600)')]],
                ['title' => 'Reversements ouvriers', 'sub' => 'Net après commission ouvrier et retenues', 'icon' => 'savings', 'tone' => 'success', 'total' => $insights->sum($scoped, 'revd'), 'totalLabel' => 'reversés', 'rows' => [$row('arev', 'À reverser', 'var(--c-warning-fg)'), $row('revc', 'En cours', 'var(--c-info)'), $row('revd', 'Effectués', 'var(--c-success-fg)'), $row('revE', 'Échoués', 'var(--c-danger)')]],
                ['title' => 'Commissions O’CHANTIER', 'sub' => 'Encaissement automatique · taux Y/Z définis dans Paramètres', 'icon' => 'percent', 'tone' => 'warning', 'total' => $net, 'totalLabel' => 'nettes', 'rows' => [$row('comc', 'Calculées', 'var(--c-info)'), $row('aenc', 'À encaisser', 'var(--c-warning-fg)'), $row('come', 'Encaissées', 'var(--c-success-fg)'), $row('comr', 'Remboursées ou extournées', 'var(--c-danger)'), $row('comn', 'Nettes', 'var(--c-primary-600)')]],
            ],
            'segments' => [
                PaymentInsights::segments('Répartition des encaissements', "{$paid->count()} paiement(s) reçu(s) sur la période", [
                    ['Net ouvriers (reversé ou à reverser)', $paidSum(fn ($c, $p) => $p->isDeposit() ? 0 : $c['net']), 'var(--c-primary-600)'],
                    ['Acomptes · provision chantier', $paidSum(fn ($c, $p) => $p->isDeposit() ? $c['gross'] + $c['bonus'] + $c['indemnity'] - $c['comO'] : 0), 'var(--c-warning-fg)'],
                    ['Commissions O’CHANTIER', $paidSum(fn ($c) => $c['com']), 'var(--c-accent-600)'],
                    ['Frais des prestataires', $paidSum(fn ($c) => $c['fees']), 'var(--c-info)'],
                    ['Retenues', $paidSum(fn ($c) => $c['deduction']), 'var(--c-danger)'],
                    ['Taxes', $paidSum(fn ($c) => $c['tax']), 'var(--c-line-strong)'],
                ]),
                PaymentInsights::segments('Cycle des commissions O’CHANTIER', "{$scoped->count()} opération(s) sur la période", [
                    ['Nettes conservées', $net, 'var(--c-success-fg)'],
                    ['À encaisser', $insights->sum($scoped, 'aenc'), 'var(--c-warning-fg)'],
                    ['Calculées ou en validation', (int) $scoped->sum(fn (Payment $p): int => in_array($ledger->calc($p)['comStatus'], ['Calculée', 'En attente de validation', 'Estimée'], true) ? $ledger->calc($p)['com'] : 0), 'var(--c-info)'],
                    ['Remboursées ou extournées', $insights->sum($scoped, 'comr'), 'var(--c-danger)'],
                ]),
            ],
            'todo' => [
                ['label' => 'Reversements à effectuer', 'icon' => 'send', 'count' => $scoped->filter(fn (Payment $p): bool => ! $p->isDeposit() && $ledger->calc($p)['paid'] && in_array($p->payout_status, ['À préparer', 'Validé', 'À reprendre'], true))->count(), 'href' => '/paiements/a-reverser'],
                ['label' => 'Reversements échoués', 'icon' => 'error', 'count' => $scoped->where('payout_status', 'Échoué')->count(), 'href' => '/paiements/a-reverser'],
                ['label' => 'Remboursements à approuver', 'icon' => 'currency_exchange', 'count' => (int) $scoped->sum(fn (Payment $p): int => $p->refunds->where('status', 'Demandé')->count()), 'href' => '/paiements/remboursements'],
                ['label' => 'Anomalies', 'icon' => 'report', 'count' => $scoped->filter(fn (Payment $p): bool => (bool) $ledger->anomalies($p))->count(), 'href' => '/paiements/anomalies'],
            ],
            'configured' => $cfg['ok'],
            'header' => $this->header('Gestion des paiements', "Encaissements promoteurs, reversements ouvriers et commissions O’CHANTIER · règle {$cfg['rule']['version']} (Paramètres)"),
        ]);
    }

    /**
     * Listes d’opérations : toutes, montants à reverser, historique des reversements.
     */
    public function operations(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        [$path, $title, $sub, $base, $selectable] = match (true) {
            $request->routeIs('payments.to-pay-out') => ['/paiements/a-reverser', 'Montants à reverser', 'Paiement promoteur confirmé · sélectionnez les opérations pour créer un lot', $items->filter(fn (Payment $p): bool => ! $p->isDeposit() && $ledger->calc($p)['paid'] && in_array($p->payout_status, ['À préparer', 'En attente de validation', 'Validé', 'Programmé', 'Échoué', 'À reprendre'], true)), true],
            $request->routeIs('payments.payouts') => ['/paiements/reversements', 'Reversements ouvriers', 'Suivi des reversements individuels et par lot', $items->filter(fn (Payment $p): bool => ! $p->isDeposit() && $p->payout_status !== 'Non disponible'), false],
            default => ['/paiements/operations', 'Toutes les opérations', 'Recherche, filtres, tri et export', $items, true],
        };

        return Inertia::render('Payments/Operations', [
            ...$this->common($insights, $path, $filters, $period),
            ...$this->list($insights, $base->values(), $filters),
            'title' => $title,
            'selectable' => $selectable,
            'payoutNav' => $path !== '/paiements/operations',
            'header' => $this->header($title, $sub, [['Paiements', '/paiements'], [$title]]),
        ]);
    }

    public function batches(Request $request): Response
    {
        [$insights, $filters, $period] = $this->context($request);
        $payments = $insights->ledger->all()->keyBy('id');
        $label = fn (int $id): array => ['reference' => $payments[$id]?->reference, 'worker' => $payments[$id]?->worker?->name, 'net' => $payments[$id] ? $insights->ledger->calc($payments[$id])['net'] : 0];

        return Inertia::render('Payments/Batches', [
            ...$this->common($insights, '/paiements/lots', $filters, $period),
            'batches' => PayoutBatch::query()->latest('executed_at')->get()->map(fn (PayoutBatch $b): array => [
                'reference' => $b->reference, 'executedAt' => $b->executed_at->format('Y-m-d H:i'), 'author' => $b->author, 'net' => $b->net, 'commission' => $b->commission, 'status' => PaymentLedger::badge($b->status),
                'results' => array_map(fn (array $r): array => [...$label($r['id']), 'result' => $r['result']], $b->results),
                'blocked' => array_map(fn (int $id): array => $label($id), $b->blocked),
            ])->all(),
            'header' => $this->header('Lots de reversements', 'Chaque opération conserve sa référence, sa commission et son résultat', [['Paiements', '/paiements'], ['Lots']]),
        ]);
    }

    /**
     * Total des commissions O’CHANTIER : indicateurs, ventilation, graphiques.
     */
    public function commissions(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        $list = $insights->filter($items, $filters, ['promoteur', 'chantier', 'type', 'moyen']);
        $all = $insights->group($list, fn (): string => 'all')['all'] ?? ['n' => 0, 'gross' => 0, 'calc' => 0, 'validated' => 0, 'collected' => 0, 'refunded' => 0, 'reversed' => 0, 'net' => 0];
        $comOf = fn (array $statuses): int => (int) $list->sum(fn (Payment $p): int => in_array($ledger->calc($p)['comStatus'], $statuses, true) ? $ledger->calc($p)['com'] : 0);
        $previous = (int) $insights->inPeriod($period['previousFrom'], $period['previousTo'])->sum(fn (Payment $p): int => $ledger->calc($p)['comNet']);
        $collectedCount = $list->filter(fn (Payment $p): bool => $ledger->calc($p)['comCollected'] > 0)->count();
        $cfg = PaymentLedger::config();
        $dimension = array_key_exists($filters['ventilation'] ?? '', self::DIMENSIONS) ? $filters['ventilation'] : 'mois';
        $by = match ($dimension) {
            'jour' => fn (Payment $p) => $p->date->format('d/m/Y'),
            'semaine' => fn (Payment $p) => 'Sem. du '.$p->date->toImmutable()->startOfWeek()->format('d/m/Y'),
            'annee' => fn (Payment $p) => $p->date->format('Y'),
            'promoteur' => fn (Payment $p) => $p->site->promoter_name,
            'chantier' => fn (Payment $p) => $p->site->name,
            'metier' => fn (Payment $p) => $p->worker?->trade ?? 'Acompte (sans ouvrier)',
            'type' => fn (Payment $p) => $p->type,
            'moyen' => fn (Payment $p) => $p->method ?? '—',
            'prestataire' => fn (Payment $p) => $p->provider ?? '—',
            default => fn (Payment $p) => $p->date->format('m/Y'),
        };
        $grouped = $insights->group($list, $by);
        ksort($grouped);
        $top = fn (callable $f): array => collect($insights->group($list, $f))->sortByDesc('net')->take(6)->map(fn (array $m, string $k): array => [$k, $m['net']])->values()->all();
        $months = $insights->group($insights->ledger->all()->filter(fn (Payment $p): bool => $p->date->year === PaymentInsights::today()->year), fn (Payment $p) => $p->date->format('m/y'));
        ksort($months);
        $tile = fn (string $label, string $value, string $icon, string $tone, ?string $sub = null): array => array_filter(['label' => $label, 'value' => $value, 'icon' => $icon, 'tone' => $tone, 'sub' => $sub]);

        return Inertia::render('Payments/Commissions', [
            ...$this->common($insights, '/paiements/commissions', $filters, $period),
            'tiles' => [
                $tile('Total net des commissions O’CHANTIER', PaymentLedger::money($all['net']), 'account_balance', 'success', 'Encaissées − remboursées − extournées · '.($previous ? ($all['net'] >= $previous ? '+' : '').(int) round(($all['net'] - $previous) / $previous * 100).' % vs période précédente' : 'pas de période de comparaison')),
                $tile('Estimées', PaymentLedger::money($comOf(['Estimée'])), 'lightbulb', 'neutral'),
                $tile('Calculées', PaymentLedger::money($all['calc']), 'calculate', 'info'),
                $tile('En attente de validation', PaymentLedger::money($comOf(['Calculée', 'En attente de validation'])), 'hourglass_top', 'warning'),
                $tile('Validées', PaymentLedger::money($all['validated']), 'verified', 'success'),
                $tile('À encaisser', PaymentLedger::money($comOf(['À encaisser'])), 'schedule', 'warning'),
                $tile('Encaissées', PaymentLedger::money($all['collected']), 'payments', 'success'),
                $tile('Remboursées', PaymentLedger::money($all['refunded']), 'currency_exchange', 'orange'),
                $tile('Extournées', PaymentLedger::money($all['reversed']), 'undo', 'danger'),
                $tile('Nombre d’opérations', (string) $all['n'], 'receipt_long', 'info'),
                $tile('Taux moyen effectif', str_replace('.', ',', (string) ($all['gross'] ? round($all['calc'] / $all['gross'] * 100, 1) : 0)).' %', 'percent', 'purple', "Y {$cfg['y']} % + Z {$cfg['z']} % (Paramètres)"),
                $tile('Commission moyenne', PaymentLedger::money($collectedCount ? (int) round($all['collected'] / $collectedCount) : 0), 'functions', 'info'),
            ],
            'dimension' => $dimension,
            'dimensions' => self::DIMENSIONS,
            'filterDefs' => $insights->filterOptions($items, ['promoteur', 'chantier', 'type', 'moyen']),
            'breakdown' => ['rows' => collect($grouped)->map(fn (array $m, string $k): array => ['label' => $k, ...$m])->values()->all(), 'total' => $all],
            'charts' => [
                ['title' => 'Évolution des commissions nettes', 'sub' => 'Par mois · année en cours', 'bars' => collect($months)->map(fn (array $m, string $k): array => [$k, $m['net']])->values()->all()],
                ['title' => 'Par chantier', 'sub' => 'Commissions nettes', 'bars' => $top(fn (Payment $p) => $p->site->name)],
                ['title' => 'Par promoteur', 'sub' => 'Commissions nettes', 'bars' => $top(fn (Payment $p) => $p->site->promoter_name)],
                ['title' => 'Par métier', 'sub' => 'Commissions nettes', 'bars' => $top(fn (Payment $p) => $p->worker?->trade ?? 'Acompte')],
                ['title' => 'Par type de prestation', 'sub' => 'Commissions nettes', 'bars' => $top(fn (Payment $p) => $p->type)],
                ['title' => 'Par moyen de paiement', 'sub' => 'Commissions nettes', 'bars' => $top(fn (Payment $p) => $p->method ?? '—')],
                ['title' => 'Comparaison', 'sub' => 'Période précédente / période', 'accent' => true, 'bars' => [['Précédente', $previous], ['Actuelle', $all['net']]]],
            ],
            'rule' => self::rule(),
            'header' => $this->header('Total des commissions O’CHANTIER', "Règle {$cfg['rule']['version']} · ".mb_strtolower($cfg['rule']['type']).' · Valeur définie dans Paramètres', [['Paiements', '/paiements'], ['Commissions']]),
        ]);
    }

    /**
     * Règle de commission en vigueur (fenêtre « Consulter la règle »).
     *
     * @return array<string, mixed>
     */
    public static function rule(): array
    {
        $cfg = PaymentLedger::config();

        return [...$cfg['rule'], 'y' => $cfg['y'], 'z' => $cfg['z'], 'previous' => $cfg['previous_rules'], 'refundRule' => $cfg['refund_rule']];
    }

    public function commissionOperations(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $list = $insights->filter($items, $filters, ['commission', 'promoteur', 'chantier', 'type', 'moyen'])
            ->sort(fn (Payment $a, Payment $b): int => ($filters['tri'] ?? '') === 'commission' ? $insights->ledger->calc($b)['com'] <=> $insights->ledger->calc($a)['com'] : $b->date <=> $a->date)->values();

        return Inertia::render('Payments/CommissionOperations', [
            ...$this->common($insights, '/paiements/commissions/operations', $filters, $period),
            'filterDefs' => $insights->filterOptions($items, ['commission', 'promoteur', 'chantier', 'type', 'moyen']),
            'payments' => PaymentInsights::paginate($list, (int) ($filters['page'] ?? 1), 10, fn (Payment $p): array => [...$insights->row($p), 'ruleVersion' => $p->rule_version]),
            'netTotal' => (int) $list->sum(fn (Payment $p): int => $insights->ledger->calc($p)['comNet']),
            'rule' => self::rule(),
            'header' => $this->header('Commissions par opération', '', [['Paiements', '/paiements'], ['Commissions', '/paiements/commissions'], ['Par opération']]),
        ]);
    }

    public function refunds(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        $inPeriod = $insights->filter($items, ['q' => $filters['q'] ?? null], []);
        $outside = $insights->filter($ledger->all()->diff($items), ['q' => $filters['q'] ?? null], []);
        $list = $inPeriod->flatMap(fn (Payment $p) => $p->refunds->map(fn (PaymentRefund $r): array => [$p, $r]))
            ->merge($outside->flatMap(fn (Payment $p) => $p->refunds->where('status', 'Demandé')->map(fn (PaymentRefund $r): array => [$p, $r])))
            ->sortByDesc(fn (array $x) => $x[1]->date)->values();
        $done = $list->filter(fn (array $x): bool => $x[1]->status === 'Effectué');

        return Inertia::render('Payments/Refunds', [
            ...$this->common($insights, '/paiements/remboursements', $filters, $period),
            'tiles' => [
                ['label' => 'Demandés', 'value' => (string) $list->filter(fn (array $x): bool => $x[1]->status === 'Demandé')->count(), 'icon' => 'pending_actions', 'tone' => 'warning'],
                ['label' => 'Effectués', 'value' => PaymentLedger::money((int) $done->sum(fn (array $x): int => $x[1]->amount)), 'icon' => 'currency_exchange', 'tone' => 'orange'],
                ['label' => 'Commission remboursée', 'value' => PaymentLedger::money((int) $done->sum(fn (array $x): int => $x[1]->commission_impact ?? $ledger->refundImpact($x[0], $x[1]))), 'icon' => 'percent', 'tone' => 'danger'],
                ['label' => 'Règle', 'value' => PaymentLedger::config()['refund_rule'], 'icon' => 'policy', 'tone' => 'info', 'sub' => 'Valeur définie dans Paramètres'],
            ],
            'refunds' => $list->map(fn (array $x): array => [...$this->refundRow($ledger, $x[0], $x[1]), 'payment' => $x[0]->reference])->all(),
            'header' => $this->header('Remboursements', 'Impact sur la commission appliqué selon la règle configurée', [['Paiements', '/paiements'], ['Remboursements']]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refundRow(PaymentLedger $ledger, Payment $p, PaymentRefund $r): array
    {
        $c = $ledger->calc($p);

        return [
            'id' => $r->id, 'date' => $r->date->format('Y-m-d'), 'type' => $r->type, 'amount' => $r->amount, 'motif' => $r->motif, 'beneficiary' => $r->beneficiary, 'responsibility' => $r->responsibility,
            'impact' => $r->commission_impact ?? $ledger->refundImpact($p, $r), 'rule' => $r->rule, 'timing' => $r->timing, 'proof' => $r->proof, 'author' => $r->author, 'approver' => $r->approver,
            'status' => PaymentLedger::badge($r->status), 'pending' => $r->status === 'Demandé',
            'commission' => $c['com'], 'commissionNet' => $c['comNet'],
        ];
    }

    public function reconciliations(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        $paid = $insights->filter($items, ['q' => $filters['q'] ?? null], [])->filter(fn (Payment $p): bool => $ledger->calc($p)['paid']);
        $list = $paid->filter(fn (Payment $p): bool => ! filled($filters['statut'] ?? null) || $p->reconciliation === $filters['statut'])->values();
        $statuses = ['Non rapproché', 'Correspondance trouvée', 'Rapproché automatiquement', 'À vérifier', 'Écart détecté', 'Rapproché manuellement'];

        return Inertia::render('Payments/Reconciliations', [
            ...$this->common($insights, '/paiements/rapprochements', $filters, $period),
            'tiles' => array_map(fn (string $s): array => ['label' => $s, 'value' => (string) $paid->where('reconciliation', $s)->count(), 'icon' => 'rule', 'tone' => ['g' => 'success', 'b' => 'info', 'r' => 'danger', 'n' => 'neutral', 'a' => 'warning', 'o' => 'orange'][PaymentLedger::tone($s)]], $statuses),
            'filterDefs' => [['key' => 'statut', 'label' => 'Statut', 'options' => [...$statuses, 'Rejeté', 'Clôturé']]],
            'payments' => PaymentInsights::paginate($list, (int) ($filters['page'] ?? 1), 10, function (Payment $p) use ($ledger, $insights): array {
                $c = $ledger->calc($p);

                return [...$insights->row($p), 'externalReference' => $p->external_reference, 'duplicate' => collect($ledger->anomalies($p))->contains('type', 'Référence externe dupliquée'), 'provider' => $p->provider, 'received' => $c['received'], 'gap' => $c['gap'], 'paidAt' => ($p->paid_at ?? $p->date)->format('Y-m-d'), 'canReconcile' => ! in_array($p->reconciliation, ['Rapproché automatiquement', 'Rapproché manuellement'], true)];
            }),
            'header' => $this->header('Rapprochements financiers', 'Comparaison des montants internes et des confirmations des prestataires', [['Paiements', '/paiements'], ['Rapprochements']]),
        ]);
    }

    public function anomalies(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $ledger = $insights->ledger;
        $all = $insights->filter($items, ['q' => $filters['q'] ?? null], [])->flatMap(fn (Payment $p) => array_map(fn (array $a): array => [$p, $a], $ledger->anomalies($p)));
        $list = $all->filter(fn (array $x): bool => (! filled($filters['type'] ?? null) || $x[1]['type'] === $filters['type']) && (! filled($filters['niveau'] ?? null) || $x[1]['level'] === $filters['niveau']))
            ->sortBy(fn (array $x): int => array_search($x[1]['level'], PaymentLedger::LEVELS, true))->values();

        return Inertia::render('Payments/Anomalies', [
            ...$this->common($insights, '/paiements/anomalies', $filters, $period),
            'tiles' => array_map(fn (string $l): array => ['label' => $l, 'value' => (string) $list->filter(fn (array $x): bool => $x[1]['level'] === $l)->count(), 'icon' => 'report', 'tone' => ['Critique' => 'danger', 'Élevée' => 'orange', 'Modérée' => 'warning', 'Faible' => 'neutral', 'Information' => 'info'][$l]], PaymentLedger::LEVELS),
            'filterDefs' => [['key' => 'type', 'label' => 'Type', 'options' => $all->map(fn (array $x): string => $x[1]['type'])->unique()->sort()->values()->all()], ['key' => 'niveau', 'label' => 'Niveau', 'options' => PaymentLedger::LEVELS]],
            'anomalies' => $list->map(fn (array $x): array => [...$x[1], 'payment' => $insights->row($x[0])])->all(),
            'header' => $this->header('Anomalies financières', 'Détection automatique · les opérations bloquantes ne peuvent pas être validées', [['Paiements', '/paiements'], ['Anomalies']]),
        ]);
    }

    public function reports(Request $request): Response
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $list = $insights->filter($items, $filters, ['promoteur', 'chantier', 'type', 'ouvrier']);
        $m = $insights->group($list, fn (): string => 'a')['a'] ?? ['n' => 0, 'gross' => 0, 'calc' => 0, 'validated' => 0, 'collected' => 0, 'refunded' => 0, 'reversed' => 0, 'net' => 0];
        $cfg = PaymentLedger::config();
        $money = fn (int $v): string => PaymentLedger::money($v);

        return Inertia::render('Payments/Reports', [
            ...$this->common($insights, '/paiements/rapports', $filters, $period),
            'filterDefs' => $insights->filterOptions($items, ['promoteur', 'chantier', 'type', 'ouvrier']),
            'tiles' => [
                ['label' => 'Opérations', 'value' => (string) $m['n'], 'icon' => 'receipt_long', 'tone' => 'info'],
                ['label' => 'Volume brut', 'value' => $money($m['gross']), 'icon' => 'work', 'tone' => 'info'],
                ['label' => 'Commissions calculées', 'value' => $money($m['calc']), 'icon' => 'calculate', 'tone' => 'info'],
                ['label' => 'Commissions validées', 'value' => $money($m['validated']), 'icon' => 'verified', 'tone' => 'success'],
                ['label' => 'Commissions encaissées', 'value' => $money($m['collected']), 'icon' => 'payments', 'tone' => 'success'],
                ['label' => 'Remboursées', 'value' => $money($m['refunded']), 'icon' => 'currency_exchange', 'tone' => 'orange'],
                ['label' => 'Extournées', 'value' => $money($m['reversed']), 'icon' => 'undo', 'tone' => 'danger'],
                ['label' => 'Commissions nettes', 'value' => $money($m['net']), 'icon' => 'account_balance', 'tone' => 'success'],
                ['label' => 'Taux moyen', 'value' => str_replace('.', ',', (string) ($m['gross'] ? round($m['calc'] / $m['gross'] * 100, 1) : 0)).' %', 'icon' => 'percent', 'tone' => 'purple'],
                ['label' => 'Règle utilisée', 'value' => $cfg['rule']['version'], 'icon' => 'policy', 'tone' => 'neutral', 'sub' => $cfg['rule']['name']],
            ],
            'rule' => self::rule(),
            'notifications' => PaymentNotification::query()->latest('sent_at')->limit(20)->get()->map(fn (PaymentNotification $n): array => ['id' => $n->id, 'type' => $n->type, 'recipient' => $n->recipient, 'channel' => $n->channel, 'sentAt' => $n->sent_at->format('Y-m-d H:i'), 'content' => $n->content, 'status' => $n->status])->all(),
            'activities' => PaymentActivity::query()->where('origin', '!=', 'Système')->orWhere('category', '!=', 'creation')->latest('occurred_at')->latest('id')->limit(40)->get()->map(fn (PaymentActivity $e): array => $this->activity($e))->all(),
            'header' => $this->header('Rapports financiers', 'Période, filtres, auteur et date de génération inclus dans chaque export', [['Paiements', '/paiements'], ['Rapports']]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activity(PaymentActivity $e): array
    {
        return [
            'id' => $e->id, 'date' => $e->occurred_at->format('Y-m-d'), 'time' => $e->occurred_at->format('H:i'), 'author' => $e->author,
            'category' => ['value' => $e->category, 'label' => ucfirst($e->category), 'tone' => AttendanceController::categoryTone($e->category === 'creation' ? 'statut' : $e->category), 'icon' => AttendanceController::categoryIcon($e->category)],
            'action' => $e->action, 'oldValue' => $e->old_value, 'newValue' => $e->new_value, 'comment' => $e->comment, 'origin' => $e->origin,
        ];
    }

    /**
     * Exports : liste d’opérations (filtres actifs), sélection, commissions, rapprochements.
     */
    public function export(Request $request, string $view): StreamedResponse
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $refs = array_filter(explode(',', (string) $request->query('refs', '')));
        $ledger = $insights->ledger;
        $list = match (true) {
            (bool) $refs => $ledger->all()->whereIn('reference', $refs)->values(),
            $view === 'a-reverser' => $insights->filter($items, $filters)->filter(fn (Payment $p): bool => ! $p->isDeposit() && $ledger->calc($p)['paid'] && in_array($p->payout_status, ['À préparer', 'Validé', 'Échoué', 'À reprendre'], true)),
            $view === 'rapprochements' => $insights->filter($items, $filters)->filter(fn (Payment $p): bool => $ledger->calc($p)['paid']),
            default => $insights->filter($items, $filters),
        };

        return $insights->csv($refs ? 'paiements-selection' : $view, $insights->sort($list->values(), $filters['tri'] ?? 'date'), $period['label']);
    }

    /**
     * Rapport de commissions imprimable (version HTML à imprimer en PDF) ou tableau Excel.
     */
    public function commissionReport(Request $request, string $format): HttpResponse
    {
        [$insights, $filters, $period, $items] = $this->context($request);
        $list = $insights->filter($items, $filters, ['promoteur', 'chantier', 'type', 'ouvrier']);
        $html = view('exports.commission-report', [
            'format' => $format, 'period' => $period['label'], 'author' => $request->user()?->name ?? 'Système', 'generatedAt' => now()->format('d/m/Y H:i'),
            'rows' => $list->map(fn (Payment $p): array => ['payment' => $p, 'calc' => $insights->ledger->calc($p)])->all(),
            'total' => $insights->group($list, fn (): string => 'a')['a'] ?? null,
            'bySite' => $insights->group($list, fn (Payment $p) => $p->site->name),
            'byPromoter' => $insights->group($list, fn (Payment $p) => $p->site->promoter_name),
            'rule' => self::rule(),
        ])->render();
        $name = 'rapport-commissions-'.PaymentInsights::today()->format('Y-m-d').($format === 'excel' ? '.xls' : '.html');

        return response($html, 200, ['Content-Type' => $format === 'excel' ? 'application/vnd.ms-excel; charset=UTF-8' : 'text/html; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /**
     * Fiche d’une opération.
     */
    public function show(Payment $payment, ?string $tab = null): Response
    {
        $tab = self::TAB_ALIASES[$tab ?? ''] ?? ($tab ?? '');
        $ledger = new PaymentLedger;
        $p = $ledger->find($payment->reference);
        abort_unless(array_key_exists($tab, self::TABS) && ($tab !== 'reversement' || ! $p->isDeposit()), 404);
        $insights = new PaymentInsights($ledger);
        $c = $ledger->calc($p);
        $anomalies = $ledger->anomalies($p);
        $base = "/paiements/operations/{$p->reference}";
        $cfg = PaymentLedger::config();
        $attendances = Attendance::with('worker', 'site', 'anomalies', 'corrections')->whereIn('reference', $p->attendance_refs)->orderBy('date')->get();

        return Inertia::render('Payments/Show', [
            'sections' => $insights->sections('/paiements/operations'),
            'payment' => [
                ...$insights->row($p),
                'key' => $p->key(), 'periodStart' => $p->period_start->format('Y-m-d'), 'periodEnd' => $p->period_end->format('Y-m-d'), 'days' => $p->days, 'openDays' => $p->open_days, 'dailyRate' => $p->daily_rate,
                'source' => $p->source, 'assignment' => $p->assignment_reference, 'calcStatus' => $p->calc_status, 'validatedBy' => $p->validated_by, 'validatedAt' => $p->validated_at?->format('Y-m-d'),
                'provider' => $p->provider, 'account' => $p->account, 'workerMethod' => $p->worker_method, 'workerAccount' => $p->worker_account, 'externalReference' => $p->external_reference,
                'payoutReference' => $p->payout_reference, 'providerMessage' => $p->provider_message, 'payoutMessage' => $p->payout_message, 'batch' => $p->batch_reference, 'attempts' => $p->attempts,
                'initiatedAt' => $p->initiated_at?->format('Y-m-d'), 'paidAt' => $p->paid_at?->format('Y-m-d'), 'payoutAt' => $p->payout_at?->format('Y-m-d'), 'reconciliationNote' => $p->reconciliation_note,
                'ruleVersion' => $p->rule_version, 'siteSlug' => $p->site->slug, 'promoterSlug' => $p->site->promoter?->slug, 'documents' => $p->documents,
            ],
            'calc' => $c,
            'anomalies' => $anomalies,
            'checklist' => $ledger->checklist($p),
            'payoutChecks' => $ledger->payoutChecks($p),
            'items' => $p->items->map(fn (PaymentItem $i): array => [...$i->only(['id', 'kind', 'type', 'amount', 'motif', 'base', 'proof', 'author', 'approver']), 'date' => $i->date->format('Y-m-d'), 'status' => PaymentLedger::badge($i->status), 'pending' => $i->status === 'En attente d’approbation'])->all(),
            'refunds' => $p->refunds->map(fn (PaymentRefund $r): array => $this->refundRow($ledger, $p, $r))->all(),
            'attendances' => $attendances->map(function (Attendance $a): array {
                $ac = AttendanceCalculator::calculate($a);

                return ['reference' => $a->reference, 'date' => $a->date->format('Y-m-d'), 'arrival' => $a->arrival['time'], 'departure' => $a->departure['time'], 'net' => AttendanceCalculator::duration($ac['net']), 'overtime' => $ac['overtime'] ? AttendanceCalculator::duration($ac['overtime']) : null, 'status' => AttendanceCalculator::badge($ac['status']), 'counted' => $a->isClosed() && ! $a->absence && $ac['departureValid']];
            })->all(),
            'activities' => $p->activities()->get()->map(fn (PaymentActivity $e): array => $this->activity($e))->all(),
            'rule' => self::rule(),
            'fees' => $cfg['fees'][$p->method] ?? [0, 0],
            'tax' => $cfg['tax'],
            'tab' => $tab,
            'tabs' => collect(self::TABS)->reject(fn (string $l, string $k): bool => $k === 'reversement' && $p->isDeposit())->map(fn (string $l, string $k): array => [$k, $l, match ($k) {
                'remboursements' => $p->refunds->where('status', 'Demandé')->count(),
                'calcul' => $p->items->where('status', 'En attente d’approbation')->count(),
                default => 0,
            }])->values()->all(),
            'options' => self::options(),
            'header' => $this->header('Fiche de l’opération', '', [['Paiements', '/paiements'], ['Opérations', '/paiements/operations'], [$p->reference, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }
}
