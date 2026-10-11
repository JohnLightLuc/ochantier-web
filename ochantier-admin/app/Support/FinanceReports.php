<?php

namespace App\Support;

use App\Models\FinanceClosing;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catalogue des rapports financiers et génération des fichiers (CSV, tableau Excel, version imprimable à enregistrer
 * en PDF). Chaque fichier rappelle la période, la base temporelle, les filtres, l’auteur, les sources, le statut des
 * données, les périodes clôturées incluses et les exclusions.
 */
class FinanceReports
{
    /**
     * Rapport : clé → libellé, droit requis.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CATALOG = [
        'synthese' => ['Rapport financier de synthèse', 'rap'], 'commissions' => ['Rapport des commissions', 'com'], 'volume' => ['Rapport du volume d’affaires', 'rap'],
        'marge' => ['Rapport de marge contributive', 'marge'], 'chantiers' => ['Rapport de rentabilité par chantier', 'marge'], 'promoteurs' => ['Rapport par promoteur', 'pro'],
        'ouvriers' => ['Rapport par ouvrier', 'ouv'], 'metiers' => ['Rapport par métier', 'rap'], 'geographie' => ['Rapport géographique', 'rap'], 'creances' => ['Rapport des créances', 'cre'],
        'balance' => ['Balance âgée', 'cre'], 'reversements' => ['Rapport des reversements', 'rev'], 'remboursements' => ['Rapport des remboursements', 'vue'],
        'frais' => ['Rapport des frais opérateurs', 'cout'], 'rapprochement' => ['Rapport de rapprochement', 'vue'], 'anomalies' => ['Rapport des anomalies', 'anom'],
        'fiscal' => ['Rapport fiscal préparatoire', 'rap'], 'journal' => ['Journal détaillé des opérations', 'com'],
    ];

    public const FORMATS = ['PDF', 'Excel', 'CSV'];

    /**
     * Regroupement demandé à l’export → rapport correspondant.
     *
     * @var array<string, string>
     */
    public const GROUPS = ['Par mois' => 'volume', 'Par chantier' => 'chantiers', 'Par promoteur' => 'promoteurs', 'Par métier' => 'metiers', 'Par commune' => 'geographie'];

    public const JOURNAL_COLUMNS = ['Réf. commission', 'Réf. paiement', 'Date de prestation', 'Date de paiement', 'Date d’encaissement', 'Promoteur', 'Chantier', 'Ouvrier', 'Type de prestation', 'Volume brut', 'Source', 'Base de calcul', 'Règle', 'Version', 'Taux', 'Commission', 'Encaissé', 'Remboursé', 'Extourné', 'Nette', 'Statut', 'Rapprochement'];

    public function __construct(public FinanceInsights $insights) {}

    /**
     * Rapports autorisés pour le rôle courant.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function allowed(): array
    {
        return collect(self::CATALOG)->filter(fn (array $r): bool => FinanceInsights::can($r[1]))->map(fn (array $r, string $k): array => ['value' => $k, 'label' => $r[0]])->values()->all();
    }

    /**
     * Lignes du journal des commissions.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<int, array<int, mixed>>
     */
    public static function journal(Collection $entries): array
    {
        $d = fn (?string $v): string => FinanceInsights::date($v);
        $rule = PaymentLedger::config()['rule']['name'];

        return $entries->map(fn (array $x): array => [
            $x['ref'], $x['opRef'], $d($x['d']['presta']), $d($x['d']['pay']), $d($x['d']['enc']), $x['promoter'], $x['site'], FinanceInsights::workerName($x), $x['prestation'], $x['gross'], $x['srcCom'],
            'Montant brut', $rule, $x['ruleVersion'], "Y {$x['y']} % · Z {$x['z']} %", $x['com'], $x['comEnc'], $x['comRef'], $x['comExt'], $x['comNet'], $x['stShow'], $x['recSt'],
        ])->values()->all();
    }

    /**
     * Colonnes et lignes d’un rapport.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function build(string $id): array
    {
        $ins = $this->insights;
        $a = $ins->a;
        $cfg = config('admin.finance');
        $t = FinanceLedger::today()->format('Y-m-d');
        $d = fn (?string $v): string => FinanceInsights::date($v);
        $hide = fn (string $right, mixed $v): mixed => FinanceInsights::can($right) ? $v : FinanceInsights::HIDDEN;
        $grouped = function (callable $by, string $label) use ($ins, $a, $hide): array {
            $line = fn (string $k, array $g): array => [$k, $g['vol'], $g['nOk'], $g['recv'], $g['comEncP'], $g['comEncO'], $g['comNet'], $hide('cout', $g['fees']), $g['refAmt'], $g['revDone'], $g['revPend'], $hide('marge', $g['marge']), $g['creance']];

            return [
                [$label, 'Volume d’affaires', 'Opérations', 'Encaissé', 'Commission Y', 'Commission Z', 'Commissions nettes', 'Frais opérateurs', 'Remboursements', 'Reversé', 'À reverser', 'Marge contributive', 'Créances'],
                [...FinanceInsights::group($ins->current, $by)->map(fn (array $g): array => $line($g['key'], $g['a']))->all(), $line('TOTAL', $a)],
            ];
        };
        $unpaid = $ins->scoped->filter(fn (array $x): bool => $x['elig'] && ! $x['paid']);

        return match ($id) {
            'commissions' => [self::JOURNAL_COLUMNS, self::journal($ins->current->where('test', false))],
            'journal' => [self::JOURNAL_COLUMNS, self::journal($ins->current)],
            'volume' => [['Mois', 'Volume d’affaires', 'Opérations réussies', 'Panier moyen'], array_map(fn (array $m): array => [FinanceLedger::monthLabel($m[2], true), $m[1]['vol'], $m[1]['nOk'], $m[1]['nOk'] ? (int) round($m[1]['volEnc'] / $m[1]['nOk']) : 'Non calculable'], $ins->byMonth($ins->scoped, fn (array $g): array => $g))],
            'marge' => [['Composante', 'Montant (FCFA)'], [
                ['Commissions nettes', $a['comNet']], ['Frais opérateurs supportés', -$a['feeOut']], ["Coûts commerciaux variables ({$cfg['sales_cost_rate']} %)", -$a['comm']],
                ['Remboursements supportés (frais non récupérables)', -$a['feeLost']], ["Autres coûts directs ({$cfg['direct_cost']} FCFA / opération)", -$a['direct']], ['Marge contributive', $a['marge']],
                ['Taux de marge contributive', FinanceInsights::percent(FinanceInsights::ratio($a['marge'], $a['comNet']))], ['Marge nette', 'Non calculée : charges fixes, amortissements, charges financières et impôts non disponibles'],
            ]],
            'chantiers' => $grouped(fn ($x) => $x['site'], 'Chantier'),
            'promoteurs' => $grouped(fn ($x) => $x['promoter'], 'Promoteur'),
            'ouvriers' => $grouped(fn ($x) => $x['workerSlug'] ? FinanceInsights::workerName($x) : '', 'Ouvrier'),
            'metiers' => $grouped(fn ($x) => $x['trade'], 'Métier'),
            'geographie' => $grouped(fn ($x) => $x['commune'], 'Commune'),
            'frais' => $grouped(fn ($x) => $x['paid'] ? $x['method'] : '', 'Moyen de paiement'),
            'creances' => [['Opération', 'Promoteur', 'Chantier', 'Échéance', 'Jours de retard', 'Montant'], $unpaid->map(fn (array $x): array => [$x['opRef'], $x['promoter'], $x['site'], $d($x['due']), max(0, FinanceLedger::days($x['due'], $t)), $x['promoterTotal']])->values()->all()],
            'balance' => (function () use ($unpaid): array {
                $b = FinanceLedger::aging($unpaid);

                return [['Promoteur', ...$b['labels'], 'Total'], array_map(fn (array $r): array => [$r['name'], ...$r['values'], $r['total']], $b['rows'])];
            })(),
            'reversements' => [['Opération', 'Ouvrier', 'Chantier', 'Net dû', 'Statut', 'Encaissé le', 'Reversé le'], $ins->current->filter(fn (array $x): bool => $x['ok'] && (bool) $x['workerSlug'])->map(fn (array $x): array => [$x['opRef'], FinanceInsights::workerName($x), $x['site'], $x['net'], $x['revSt'], $d($x['d']['enc']), $d($x['d']['rev'])])->values()->all()],
            'remboursements' => [['Opération', 'Promoteur', 'Type', 'Motif', 'Montant', 'Commission remboursée', 'Statut', 'Date'], $ins->scoped->flatMap(fn (array $x): array => array_map(fn (array $r): array => [$x['opRef'], $x['promoter'], $r['type'], $r['motif'], $r['amount'], $x['comRef'], $r['status'], $d($r['date'])], $x['refunds']))->values()->all()],
            'rapprochement' => [['Opération', 'Référence externe', 'Attendu', 'Reçu', 'Écart', 'Statut'], $ins->current->where('ok', true)->map(fn (array $x): array => [$x['opRef'], $x['extRef'], $x['promoterTotal'], $x['received'], $x['received'] - $x['promoterTotal'], $x['recSt']])->values()->all()],
            'anomalies' => [['Niveau', 'Alerte', 'Détail'], array_map(fn (array $r): array => [$r['level'], $r['title'], $r['text']], $ins->risks())],
            'fiscal' => [['Mois', 'Commissions nettes (revenu)', 'Frais opérateurs (charge)', 'Taxes collectées pour tiers (hors revenu)', 'Reversements ouvriers (hors revenu)'], array_map(fn (array $m): array => [FinanceLedger::monthLabel($m[2], true), $m[1]['comNet'], $m[1]['fees'], 0, $m[1]['revDone']], $ins->byMonth($ins->scoped, fn (array $g): array => $g))],
            default => [['Indicateur', 'Valeur', 'Précédent', 'Variation', 'Formule'], array_map(function (string $k) use ($ins): array {
                $v = $ins->kpi($k);

                return [$v['name'], $v['text'], $v['prevText'], $v['cmp'], $v['formula']];
            }, array_keys(FinanceInsights::definitions()))],
        };
    }

    /**
     * En-tête d’un rapport : contexte d’analyse et provenance des données.
     *
     * @param  array<int, array{0: string, 1: string}>  $extra
     * @return array<int, array{0: string, 1: string}>
     */
    public function meta(string $name, array $extra = []): array
    {
        $ins = $this->insights;
        $p = $ins->period;
        $closed = FinanceClosing::query()->where('status', 'Clôturée')->whereBetween('month', [substr($p['from'], 0, 7), substr($p['to'], 0, 7)])->orderBy('month')->get()
            ->map(fn (FinanceClosing $c): string => FinanceLedger::monthLabel($c->month)." (instantané v{$c->version})")->implode(', ');

        return [
            ['Rapport', $name], ['Période', "{$p['range']} ({$p['label']})"], ['Base temporelle', $ins->basisLabel()], ['Filtres', $ins->filterSummary() ?: 'Aucun filtre'],
            ['Date de génération', now()->format('d/m/Y H:i')], ['Utilisateur', (auth()->user()?->name ?? 'Système').' · '.FinanceInsights::role()], ['Devise', 'Franc CFA (FCFA / XOF)'],
            ['Sources', 'Module Paiements · journal des commissions · archives des périodes clôturées'], ['Statut des données', 'Données à jour.'], ['Dernière synchronisation', FinanceInsights::lastSync()],
            ['Périodes clôturées incluses', $closed ?: 'Aucune'], ['Exclusions', "{$ins->a['exclTest']} test(s), {$ins->a['exclDup']} doublon(s), {$ins->a['excl']} opération(s) non éligible(s)"],
            ...$extra,
        ];
    }

    /**
     * Fichier téléchargeable : CSV, tableau Excel (HTML) ou version imprimable (HTML qui ouvre l’impression).
     *
     * @param  array<int, array{0: string, 1: string}>  $meta
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function file(string $name, string $format, array $meta, array $columns, array $rows): Response
    {
        $base = Str::slug($name).'-'.FinanceLedger::today()->format('Y-m-d');
        if ($format === 'CSV') {
            $line = fn (array $values): string => implode(';', array_map(fn ($v): string => str_replace([';', "\n"], [',', ' '], (string) $v), $values));
            $csv = "\u{FEFF}".implode("\n", [...array_map($line, $meta), '', $line($columns), ...array_map($line, $rows)]);

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}.csv\""]);
        }
        $html = view('exports.finance-report', ['name' => $name, 'meta' => $meta, 'columns' => $columns, 'rows' => $rows, 'print' => $format === 'PDF'])->render();

        return $format === 'Excel'
            ? response($html, 200, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}.xls\""])
            : response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$base}-imprimable.html\""]);
    }
}
