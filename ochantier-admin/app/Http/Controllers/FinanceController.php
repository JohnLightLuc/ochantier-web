<?php

namespace App\Http\Controllers;

use App\Models\FinanceAudit;
use App\Models\FinanceClosing;
use App\Models\FinanceReport;
use App\Models\FinanceView;
use App\Models\PaymentActivity;
use App\Support\FinanceBlocks as UI;
use App\Support\FinanceInsights as Fin;
use App\Support\FinanceLedger;
use App\Support\FinanceReports;
use App\Support\PaymentLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance & Reporting : centre financier en lecture analytique des opérations du module Paiements et des archives
 * clôturées (commissions, volume d’affaires, marge contributive, créances, reversements, trésorerie, remboursements,
 * frais, rapprochements, risques, objectifs, prévisions, clôtures, rapports, définitions et audit).
 *
 * Chaque page décrit ses blocs (tuiles, alertes, parcours, graphiques, cartes, tableaux) et ses actions ; la période,
 * la base temporelle et les filtres globaux sont conservés d’une page à l’autre.
 */
class FinanceController extends Controller
{
    /**
     * Liens rapides : libellé, icône, chemin.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const LINKS = [['Vue d’ensemble', 'monitoring', ''], ['Journal des commissions', 'receipt_long', '/commissions'], ['Par chantier', 'construction', '/chantiers'], ['Par promoteur', 'apartment', '/promoteurs'], ['Rapports', 'summarize', '/rapports']];

    /**
     * Analyses par axe : titre, libellé d’un élément, icône, droit, rapport.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const DIMENSIONS = [
        'chantiers' => ['Analyse par chantier', 'chantier', 'construction', 'vue', 'chantiers'],
        'promoteurs' => ['Analyse par promoteur', 'promoteur', 'apartment', 'pro', 'promoteurs'],
        'ouvriers' => ['Analyse par ouvrier', 'ouvrier', 'engineering', 'ouv', 'ouvriers'],
        'metiers' => ['Analyse par métier', 'métier', 'handyman', 'vue', 'metiers'],
        'geographie' => ['Analyse géographique', 'commune', 'map', 'vue', 'geographie'],
    ];

    public const AXES = ['ville' => 'Ville', 'commune' => 'Commune', 'zone' => 'Zone', 'agence' => 'Agence'];

    public const COMMISSION_TONES = ['Encaissée' => 'g', 'À encaisser' => 'a', 'Partiellement remboursée' => 'o', 'Remboursée' => 'o', 'Annulée' => 'n', 'Extournée' => 'r', 'Suspendue' => 'r', 'Partiellement encaissée' => 'a', 'Corrigée' => 'b', 'Test (exclue)' => 'n', 'Doublon (exclu)' => 'r', 'Calcul non validé' => 'n'];

    public const RECONCILIATION_TONES = ['Rapproché' => 'g', 'Écart traité' => 'g', 'Avec écart' => 'r', 'En attente' => 'a', 'Non éligible' => 'n'];

    private function insights(Request $request): Fin
    {
        return new Fin(new FinanceLedger, $request->query());
    }

    /**
     * Rendu d’une page : contexte d’analyse commun et blocs propres à la page.
     *
     * @param  array<string, mixed>  $props
     * @param  array<int, array{0: string, 1?: string|null}>  $crumbs
     */
    private function render(Fin $ins, string $page, array $props, string $title, string $subtitle, array $crumbs = []): Response
    {
        $today = FinanceLedger::today();
        $operations = $ins->all->where('archived', false)->count();
        $active = '/'.explode('/', match ($page) {
            'commission' => 'commissions',
            'analysis' => (string) ($props['dimension'] ?? ''),
            'reports' => 'rapports',
            'home' => '',
            default => $page,
        })[0];

        return Inertia::render('Finance/Page', [
            'page' => $page,
            'nav' => array_map(fn (array $l): array => ['label' => $l[0], 'icon' => $l[1], 'href' => $ins->url($l[2]), 'active' => $active === ($l[2] ?: '/')], self::LINKS),
            'period' => array_intersect_key($ins->period, array_flip(['key', 'label', 'range', 'from', 'to'])),
            'periods' => Fin::PERIODS,
            'basis' => $ins->basis,
            'bases' => FinanceLedger::BASES,
            'query' => array_filter(array_map('strval', array_diff_key($ins->query, ['page' => true])), fn (string $v): bool => $v !== ''),
            'search' => false,
            'banner' => UI::alert('success', 'cloud_done', 'Données à jour.', (isset($props['stockDate']) ? 'Encours au '.$today->format('d/m/Y').($props['stockDate'] === 'period' ? ' (indépendant de la période)' : '').' · ' : '').'Dernière synchronisation : '.Fin::lastSync()." · Source : Paiements ({$operations} opérations) + archives clôturées · Couverture : ".Fin::date(FinanceLedger::months()[0].'-01').' → '.$today->format('d/m/Y').' · Base : '.$ins->basisLabel().'.', 'Actualiser', UI::post('/finance-reporting/synchronisation')),
            'selects' => [],
            'views' => FinanceView::query()->orderBy('id')->get()->map(fn (FinanceView $v): array => ['name' => $v->name, 'href' => '/finance-reporting?'.http_build_query(array_filter([...$v->filters, 'base' => $v->basis, 'periode' => $v->period]))])->all(),
            'kpis' => [], 'chips' => [], 'alerts' => [], 'tiles' => [], 'toolbar' => [], 'flow' => null, 'charts' => [], 'panels' => [], 'tables' => [], 'timeline' => [], 'missing' => null,
            'sheets' => (object) [],
            'rule' => [...PaymentController::rule(), 'ok' => PaymentLedger::config()['ok']],
            'canParams' => Fin::can('param'),
            'reports' => FinanceReports::allowed(),
            'exportInfo' => "Période : {$ins->period['range']} · Filtres : ".($ins->filterSummary() ?: 'aucun').' · '.$ins->current->count().' ligne(s) détaillée(s) · Données sensibles incluses : '.(Fin::can('ouv') || Fin::can('marge') ? 'oui (montants ouvriers, marges)' : 'non').' · Autorisation nécessaire : Exporter ('.Fin::role().').',
            ...array_diff_key($props, ['stockDate' => true]),
            'header' => $this->header($title, $subtitle, $crumbs ? [['Finance & Reporting', $ins->url('')], ...$crumbs] : []),
        ]);
    }

    /**
     * Page refusée ou introuvable.
     */
    private function missing(Fin $ins, string $title, string $text, string $back = ''): Response
    {
        return $this->render($ins, 'missing', ['missing' => ['title' => $title, 'text' => $text, 'back' => $ins->url($back)], 'banner' => null], $title, '', [[$title]]);
    }

    /**
     * Barre de filtres (base temporelle, filtres essentiels ou complets, sélections propres à la page) et alerte
     * de filtres actifs.
     *
     * @param  array<int, array<string, mixed>>  $extra
     * @return array{selects: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>}
     */
    private function filterBar(Fin $ins, string $path, array $extra = []): array
    {
        $all = (bool) ($ins->query['tous'] ?? false);
        $selects = [
            ['key' => 'base', 'label' => 'Base de la période', 'value' => $ins->basis, 'options' => collect(FinanceLedger::BASES)->map(fn (string $l, string $k): array => ['value' => $k, 'label' => "Base : {$l}"])->values()->all()],
            ...array_map(fn (array $f): array => [...$f, 'options' => [['value' => '', 'label' => $f['label']], ...$f['options']]], $ins->filterSelects($all)),
            ...$extra,
            ['key' => 'tous', 'label' => 'Affichage des filtres', 'value' => $all ? '1' : '', 'options' => [['value' => '', 'label' => 'Filtres essentiels'], ['value' => '1', 'label' => 'Tous les filtres ('.(count(Fin::FILTERS) - (Fin::can('ouv') ? 0 : 1)).')']]],
        ];
        $alerts = $ins->filters ? [UI::alert('info', 'filter_alt', 'Filtres appliqués ('.count($ins->filters).')', $ins->filterSummary().' · tous les KPI, graphiques, tableaux et exports sont recalculés.', 'Réinitialiser', UI::link($ins->url($path, [], false)))] : [];

        return ['selects' => $selects, 'alerts' => $alerts];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $options
     * @return array<string, mixed>
     */
    private function select(Fin $ins, string $key, string $label, array $options, string $prefix): array
    {
        return ['key' => $key, 'label' => $label, 'value' => (string) ($ins->query[$key] ?? ''), 'options' => array_map(fn (array $o): array => ['value' => $o[0], 'label' => $prefix.$o[1]], $options)];
    }

    /**
     * Outils de vue : réinitialiser, enregistrer, partager, exporter, définitions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function viewTools(Fin $ins, string $path, string $report = 'synthese'): array
    {
        $context = [...array_intersect_key($ins->query, array_flip(['periode', 'du', 'au'])), 'base' => $ins->basis, 'filtres' => $ins->filters];

        return [
            UI::button('Réinitialiser'.($ins->filters ? ' ('.count($ins->filters).')' : ''), 'filter_alt_off', UI::link($ins->url($path, [], false))),
            UI::button('Enregistrer la vue', 'bookmark_add', UI::form('Enregistrer la vue', "Période « {$ins->period['label']} », base « {$ins->basisLabel()} » et ".count($ins->filters).' filtre(s).', 'bookmark_add', 'Enregistrer', '/finance-reporting/vues', [['name' => 'name', 'label' => 'Nom de la vue', 'required' => true, 'full' => true, 'placeholder' => 'Ex. Créances Cocody · trimestre']], 'primary', $context)),
            UI::button('Partager la vue', 'share', Fin::can('rap') ? UI::form('Partager la vue', 'Les destinataires ne verront que les données autorisées par leur propre rôle.', 'share', 'Partager', '/finance-reporting/vues/partage', [['name' => 'to', 'label' => 'Destinataire', 'type' => 'select', 'required' => true, 'options' => ['Direction générale', 'Contrôle de gestion', 'Responsable paiements', 'Responsable commercial'], 'full' => true]], 'primary', $context) : UI::deny('partage')),
            UI::button('Exporter la vue', 'download', UI::export($report)),
            UI::button('Définitions', 'menu_book', UI::link($ins->url('/definitions'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>|null
     */
    private static function paymentLink(array $x): ?array
    {
        return $x['paymentRef'] ? UI::link("/paiements/operations/{$x['paymentRef']}") : null;
    }

    /**
     * Fiches des indicateurs (panneau latéral).
     *
     * @return array<string, array<string, mixed>>
     */
    private function sheets(Fin $ins): array
    {
        $sync = Fin::lastSync();

        return collect(array_keys(Fin::definitions()))->mapWithKeys(fn (string $id): array => [$id => $ins->kpiSheet($id, $sync)])->all();
    }

    /**
     * Génération directe d’un rapport (téléchargement), si le rôle l’autorise.
     *
     * @return array<string, string>
     */
    private function generate(Fin $ins, string $report, string $format): array
    {
        [$name, $right] = FinanceReports::CATALOG[$report];

        return match (true) {
            ! Fin::can('export') => UI::deny('export'),
            ! Fin::can($right) => UI::deny($name),
            default => UI::download($ins->url('/rapports/generer', ['rapport' => $report, 'format' => $format])),
        };
    }

    /**
     * Tableau de contrôle de cohérence avec Paiements.
     *
     * @param  array{ok: bool, count: int, rows: array<int, array<string, mixed>>}  $c
     * @return array<string, mixed>
     */
    private function consistencyTable(Fin $ins, array $c): array
    {
        $sync = Fin::lastSync();

        return UI::table('fact_check', 'Contrôle de cohérence avec Paiements', "Mêmes période, base et filtres · {$c['count']} opération(s) source", ['Donnée source', 'Montant Finance & Reporting', 'Montant Paiements', 'Écart', 'Statut', 'Dernière synchronisation'], array_map(fn (array $r): array => UI::row($r['label'], [
            UI::stacked($r['label'], $r['source']), UI::money($r['finance'], true), UI::money($r['payments']), $r['gap'] ? UI::money($r['gap']) : UI::text('0 FCFA', false, true), UI::badge($r['status'], $r['tone']), UI::text($sync),
        ], UI::link($ins->url('/rapprochements')), [['visibility', 'Analyser les écarts', UI::link($ins->url('/rapprochements'))], ['open_in_new', 'Ouvrir Paiements', UI::link('/paiements')]]), $c['rows']), 1000);
    }

    /**
     * Journal des commissions rattachées (fiche d’un chantier, d’un promoteur ou d’un ouvrier).
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function commissionTable(Fin $ins, Collection $entries, int $page): array
    {
        $p = UI::paginate($entries->sortByDesc(fn (array $x): string => $x['d']['op'])->values(), $page, fn (array $x): array => UI::row($x['ref'], [
            UI::text($x['ref'], true), UI::text($x['opRef']), UI::text(Fin::date($x['d']['op'])), UI::text($x['site']), UI::text(Fin::workerName($x)), UI::money($x['gross']), UI::money($x['com']), UI::money($x['comNet'], true),
            UI::badge($x['stShow'], self::COMMISSION_TONES[$x['stShow']] ?? 'o'),
        ], UI::link($ins->url("/commissions/{$x['ref']}")), [['visibility', 'Détail', UI::link($ins->url("/commissions/{$x['ref']}"))]]));

        return UI::table('receipt_long', 'Commissions rattachées', $p['label'], ['Commission', 'Paiement', 'Date d’opération', 'Chantier', 'Ouvrier', 'Volume brut', 'Commission', 'Nette', 'Statut'], $p['rows'], 1300, ['receipt_long', 'Aucune commission', 'Aucune opération sur la période et les filtres actifs.'], $p['pagination']);
    }

    /**
     * Vue d’ensemble : KPI, alertes de risque, graphiques, marge contributive, obligations, analyses et contrôle.
     */
    public function index(Request $request): Response
    {
        $ins = $this->insights($request);
        $a = $ins->a;
        $st = $ins->stock;
        $cfg = config('admin.finance');
        $rt = PaymentLedger::config();
        $range = $ins->period['range'];
        $basis = mb_strtolower($ins->basisLabel());
        $unit = " · FCFA · base : {$basis}";
        $none = $a['n'] ? '' : ' · aucune donnée';
        $can = fn (string $r): bool => Fin::can($r);
        $hidden = fn (string $r, int|float|null $v): string => $can($r) ? Fin::money($v) : Fin::HIDDEN;
        $k = fn (int|float $v): string => Fin::thousands($v);

        $kpis = array_map(function (array $d) use ($ins, $a, $basis): array {
            [$id, $icon, $tone] = $d;
            $v = $ins->kpi($id);
            $value = $v['text'];
            $hint = match ($id) {
                'com_enc' => "{$v['cmp']} · {$a['nOk']} opération(s) · MAJ ".substr(Fin::lastSync(), -5),
                'vol' => "{$v['cmp']} · {$a['n']} opération(s) éligible(s)",
                'marge' => $v['ok'] ? "{$v['cmp']} · ".Fin::percent(Fin::ratio($a['marge'], $a['comNet'])).' des commissions nettes' : $v['cmp'],
                default => "{$a['pendN']} op. · ancienneté moy. ".Fin::daysText($a['pendAvg']).' · échu '.Fin::money($a['pendDue']).' · en retard '.Fin::money($a['pendLate']),
            };
            if ($id === 'com_pend' && in_array($ins->basis, ['pay', 'enc', 'rev', 'rec'], true)) {
                $value = 'Non calculable';
                $hint = "Base « {$basis} » : les commissions non encaissées n’ont pas cette date";
            }

            return ['label' => $v['name'], 'value' => $value, 'icon' => $icon, 'tone' => $tone, 'hint' => $hint, 'action' => UI::kpi($id)];
        }, [['com_enc', 'paid', 'g'], ['vol', 'account_balance_wallet', 'b'], ['marge', 'trending_up', $a['marge'] >= 0 ? 'g' : 'r'], ['com_pend', 'schedule', 'a']]);

        $chips = [
            ['label' => 'Commission promoteur (Y)', 'value' => "{$rt['y']} % · ".Fin::money($a['comEncP']), 'icon' => 'person', 'action' => UI::kpi('com_p')],
            ['label' => 'Commission ouvrier (Z)', 'value' => "{$rt['z']} % · ".Fin::money($a['comEncO']), 'icon' => 'groups', 'action' => UI::kpi('com_o')],
            ['label' => 'Taux effectif encaissé', 'value' => Fin::percent(Fin::ratio($a['comEnc'], $a['brutEnc'])), 'icon' => 'percent', 'action' => UI::kpi('taux_enc')],
            ['label' => 'Taux de captation', 'value' => Fin::percent(Fin::ratio($a['comNet'], $a['vol'])), 'icon' => 'donut_large', 'action' => UI::kpi('captation')],
            ['label' => 'Opérations', 'value' => "{$a['nOk']} réussie(s)", 'icon' => 'receipt', 'action' => UI::link($ins->url('/commissions'))],
            ['label' => 'Remboursées', 'value' => Fin::money($a['refAmt']), 'icon' => 'undo', 'action' => UI::link($ins->url('/remboursements'))],
        ];

        $bar = $this->filterBar($ins, '');
        $alerts = $bar['alerts'];
        if (! $rt['y'] || ! $rt['z']) {
            $alerts[] = UI::alert('warning', 'warning', 'Commission désactivée', (! $rt['y'] ? 'La commission promoteur (Y) ' : 'La commission ouvrier (Z) ').'est désactivée dans Paramètres : aucune nouvelle entrée n’est générée.', 'Taux de commission', UI::rates());
        }
        $risks = $ins->risks();
        $top = array_values(array_filter($risks, fn (array $r): bool => in_array($r['level'], ['Critique', 'Élevé'], true)));
        foreach (array_slice($top, 0, 3) as $r) {
            $alerts[] = UI::alert($r['level'] === 'Critique' ? 'danger' : 'warning', $r['icon'], "{$r['level']} · {$r['title']}", $r['text'], $r['cta'], $r['rates'] ? UI::rates() : UI::link($r['href']));
        }
        if (count($risks) > 3) {
            $alerts[] = UI::alert('info', 'report', count($risks).' alertes de risque actives', 'dont '.count($top).' élevée(s) ou critique(s).', 'Voir toutes les alertes', UI::link($ins->url('/anomalies')));
        }

        $months = $ins->byMonth($ins->scoped, fn (array $g): array => $g);
        $bySite = Fin::group($ins->current, fn ($x) => $x['site']);
        $byCommune = Fin::group($ins->current, fn ($x) => $x['commune']);
        $charts = [
            UI::chart('Évolution mensuelle des commissions', 'Commissions encaissées · 12 derniers mois'.$unit, array_map(fn (array $m): array => [$m[0], $m[1]['comEnc'], $k($m[1]['comEnc'])], $months)),
            UI::chart('Répartition par source', "Commissions · {$range} · FCFA{$none}", array_map(fn (array $s): array => [$s[0], $s[1], $k($s[1])], [['Promoteur (Y)', $a['comEncP']], ['Ouvrier (Z)', $a['comEncO']], ['Remboursées', -$a['comRef']], ['Nettes', $a['comNet']]])),
            UI::chart('Top chantiers', "Commissions nettes · {$range} · FCFA{$none}", $bySite->take(6)->map(fn (array $g): array => [implode(' ', array_slice(explode(' ', $g['key']), 0, 2)), $g['a']['comNet'], $k($g['a']['comNet'])])->all()),
            UI::chart('Commissions par commune', "Commissions nettes · {$range} · FCFA{$none}", $byCommune->take(6)->map(fn (array $g): array => [$g['key'], $g['a']['comNet'], $k($g['a']['comNet'])])->all(), 'var(--c-accent-600)'),
            UI::chart('Volume d’affaires et commissions', 'Volume net traité · 12 derniers mois'.$unit.' · infobulle : commissions nettes', array_map(fn (array $m): array => [$m[0], $m[1]['vol'], Fin::millions($m[1]['vol']).' · '.$k($m[1]['comNet'])], $months)),
            UI::chart('Marge contributive', $can('marge') ? 'Mensuelle · 12 derniers mois'.$unit : 'Accès restreint à votre rôle', $can('marge') ? array_map(fn (array $m): array => [$m[0], $m[1]['marge'], $k($m[1]['marge'])], $months) : [], 'var(--c-success)'),
        ];

        $unpaid = $ins->scoped->filter(fn (array $x): bool => $x['elig'] && ! $x['paid']);
        $aging = FinanceLedger::aging($unpaid);
        $refunds = $ins->current->flatMap(fn (array $x): array => array_filter($x['refunds'], fn (array $r): bool => $r['status'] === 'Effectué'));
        $methods = Fin::group($ins->current->where('paid', true), fn ($x) => $x['method']);
        $flow = [
            'steps' => [
                UI::step('Commissions nettes', Fin::money($a['comNet']), 'done', 'paid', UI::kpi('com_net')),
                UI::step('− Frais opérateurs supportés', $hidden('cout', $a['feeOut']).' · reversements', 'warn', 'swap_horiz', UI::link($ins->url('/frais-operateurs'))),
                UI::step('− Coûts commerciaux variables', $hidden('cout', $a['comm'])." · {$cfg['sales_cost_rate']} % des commissions nettes", 'warn', 'badge'),
                UI::step('− Remboursements supportés', $hidden('cout', $a['feeLost']).' · frais non récupérables', 'warn', 'undo', UI::link($ins->url('/remboursements'))),
                UI::step('− Autres coûts directs', $hidden('cout', $a['direct'])." · {$cfg['direct_cost']} FCFA / opération", 'warn', 'receipt'),
                UI::step('= Marge contributive', $hidden('marge', $a['marge']).($can('marge') ? ' · '.Fin::percent(Fin::ratio($a['marge'], $a['comNet'])) : ''), $a['marge'] >= 0 ? 'cur' : 'fail', 'savings', UI::kpi('marge')),
            ],
            'stacks' => [
                UI::stack('Encaissements et reversements', $range, [['Commissions nettes conservées', $a['comNet']], ['Reversé aux ouvriers', $a['revDone']], ['Encaissé à reverser', $a['revPend']], ['Frais d’encaissement', $a['feeIn']], ['Remboursé', $a['refAmt']]]),
                UI::stack('Créances par ancienneté', 'Encours au '.FinanceLedger::today()->format('d/m/Y'), array_map(fn (string $l, int $v): array => [$l, $v], $aging['labels'], $aging['totals'])),
                UI::stack('Performance des moyens de paiement', "Volume encaissé · {$range}", $methods->map(fn (array $g): array => ["{$g['key']} · {$g['a']['nOk']} op.", $g['a']['recv']])->all()),
                UI::stack('Remboursements', $range, [
                    ["Partiels ({$a['refPart']})", (int) $refunds->reject(fn (array $r): bool => str_contains(mb_strtolower($r['type']), 'total'))->sum('amount')],
                    ["Totaux ({$a['refTot']})", (int) $refunds->filter(fn (array $r): bool => str_contains(mb_strtolower($r['type']), 'total'))->sum('amount')],
                    ['En attente d’approbation', $a['refPend']],
                ]),
            ],
        ];

        $tiles = [
            UI::tile('Commission promoteur (Y)', Fin::money($a['comEncP']), 'person', 'b', ['bar' => Fin::ratio($a['comEncP'], $a['comEnc']) ?? 0, 'sub' => "{$rt['y']} % · valeur définie dans Paramètres"]),
            UI::tile('Commission ouvrier (Z)', Fin::money($a['comEncO']), 'groups', 'b', ['bar' => Fin::ratio($a['comEncO'], $a['comEnc']) ?? 0, 'sub' => "{$rt['z']} % · valeur définie dans Paramètres"]),
            UI::tile('Coûts commerciaux variables', $can('cout') ? '− '.Fin::money($a['comm']) : Fin::HIDDEN, 'badge', 'o', ['sub' => 'Commissions du réseau terrain']),
            UI::tile('Frais opérateurs supportés', $can('cout') ? '− '.Fin::money($a['feeOch']) : Fin::HIDDEN, 'swap_horiz', 'o', ['sub' => 'Reversements et frais non récupérables · hors commissions']),
            UI::tile('Marge contributive', $hidden('marge', $a['marge']), 'savings', $a['marge'] >= 0 ? 'g' : 'r', ['bar' => $can('marge') ? (Fin::ratio($a['marge'], $a['comNet']) ?? 0) : 0, 'sub' => $can('marge') ? Fin::percent(Fin::ratio($a['marge'], $a['comNet'])).' des commissions nettes · marge nette non calculée (charges fixes absentes)' : 'Accès restreint']),
            UI::tile('Taux de captation', Fin::percent(Fin::ratio($a['comNet'], $a['vol'])), 'donut_large', 'p', ['bar' => Fin::ratio($a['comNet'], $a['vol']) ?? 0, 'sub' => "Objectif {$cfg['objectives']['capture']} % · Y ".Fin::percent(Fin::ratio($a['comNetP'], $a['vol'])).' · Z '.Fin::percent(Fin::ratio($a['comNetO'], $a['vol']))]),
        ];

        $kpiCard = function (string $id) use ($ins): array {
            $v = $ins->kpi($id);
            $icon = ['Revenus' => 'paid', 'Activité' => 'query_stats', 'Rentabilité' => 'savings', 'Qualité' => 'verified', 'Coûts' => 'swap_horiz', 'Obligations' => 'account_balance', 'Trésorerie' => 'water_drop'][$v['cat']];

            return UI::card($icon, $v['cat'], $v['name'], "{$v['text']} · {$v['cmp']}", $v['delta'] ?: ($v['stock'] ? 'Encours' : '—'), $v['delta'] ? (str_starts_with($v['delta'], '+') ? 'g' : 'o') : 'n', UI::kpi($id));
        };
        $linkCard = fn (string $icon, string $kicker, string $title, string $sub, string $badge, string $tone, string $path, ?string $right = null): array => $right && ! $can($right)
            ? UI::card($icon, $kicker, $title, 'Accès restreint à votre rôle', 'Restreint', 'n', UI::deny($title))
            : UI::card($icon, $kicker, $title, $sub, $badge, $tone, UI::link($ins->url($path)));
        $consistency = $ins->consistency();
        $closing = FinanceClosing::query()->where('month', FinanceLedger::months()[count(FinanceLedger::months()) - 2])->first();
        $closingStatus = $closing->status ?? 'Ouverte';
        $panels = [
            UI::cards('insights', 'Indicateurs financiers', array_map($kpiCard, ['com_brut', 'com_net', 'taux_net', 'panier', 'com_moy', 'taux_ref', 'taux_rap', 'fail_pay']), UI::button('Tous les indicateurs', 'menu_book', UI::link($ins->url('/definitions')))),
            UI::cards('account_balance', 'Obligations et trésorerie', [
                $linkCard('request_quote', 'Créances promoteurs', Fin::money($st['creance']), 'Échu '.Fin::money($st['creEch']).' · taux de recouvrement '.Fin::percent(Fin::ratio($a['dueOk'], $a['dueTot'])), "{$st['pendN']} op.", $st['creEch'] ? 'a' : 'g', '/creances', 'cre'),
                $linkCard('send', 'Reversements ouvriers', Fin::money($st['revPend']).' à reverser', 'Reversé sur la période '.Fin::money($a['revDone']).' · délai moyen '.Fin::daysText($a['avgRev']), $st['revFailN'] ? "{$st['revFailN']} échec(s)" : 'OK', $st['revFailN'] ? 'r' : 'g', '/reversements', 'rev'),
                $linkCard('water_drop', 'Trésorerie opérationnelle', 'Flux net suivi '.Fin::money($a['flux']), 'Couverture des reversements '.Fin::percent(Fin::ratio($st['fonds'], $st['revPend'])), 'Sans solde bancaire', 'n', '/tresorerie', 'cout'),
                $linkCard('undo', 'Remboursements et extournes', Fin::money($a['refAmt']), "{$a['refN']} remboursement(s) · ".Fin::percent(Fin::ratio($a['refAmt'], $a['recv'])), $a['refPend'] ? 'En attente' : 'À jour', $a['refPend'] ? 'a' : 'g', '/remboursements'),
                $linkCard('swap_horiz', 'Frais des opérateurs', Fin::money($a['fees']), Fin::percent(Fin::ratio($a['fees'], $a['recv'] + $a['revDone'])).' des flux · hors commissions', $methods->count().' moyen(s)', 'b', '/frais-operateurs', 'cout'),
                $linkCard('online_prediction', 'Prévisions', 'Flux projeté (estimation)', 'Paiements attendus '.Fin::money($st['creance']).' · reversements à venir '.Fin::money($st['revPend']), 'Estimation', 'p', '/previsions', 'cout'),
            ]),
            UI::cards('analytics', 'Analyses et contrôle', [
                $linkCard('engineering', 'Par ouvrier', 'Analyse des rémunérations', 'Montants bruts, commissions supportées, reversements', 'Sensible', 'o', '/ouvriers', 'ouv'),
                $linkCard('handyman', 'Par métier', 'Volume, panier et marge par métier', Fin::group($ins->current, fn ($x) => $x['trade'])->count().' métier(s) actif(s)', 'Analyse', 'b', '/metiers'),
                $linkCard('map', 'Géographie', 'Villes, communes, zones et agences', $byCommune->count().' commune(s) active(s)', 'Analyse', 'b', '/geographie'),
                $linkCard('fact_check', 'Rapprochements', 'Taux '.Fin::percent(Fin::ratio($a['recOk'], $a['recElig'])), $consistency['ok'] ? 'Cohérence avec Paiements vérifiée' : 'Écart non expliqué avec Paiements', $consistency['ok'] ? 'Cohérent' : 'Écart', $consistency['ok'] ? 'g' : 'r', '/rapprochements'),
                $linkCard('report', 'Anomalies et risques', count($risks).' alerte(s)', count($top).' élevée(s) ou critique(s)', $top ? 'À traiter' : 'OK', $top ? 'r' : 'g', '/anomalies', 'anom'),
                $linkCard('flag', 'Objectifs et budgets', 'Réalisé vs objectif', 'Commissions nettes '.Fin::percent(Fin::ratio($a['comNet'], $cfg['objectives']['commissions'] * $ins->period['days'] / 30.4)).' de l’objectif', 'Paramètres', 'b', '/objectifs'),
                $linkCard('lock_clock', 'Clôtures', FinanceLedger::monthLabel(FinanceLedger::months()[count(FinanceLedger::months()) - 2], true)." · {$closingStatus}", 'Instantanés financiers par période', $closingStatus, $closingStatus === 'Clôturée' ? 'g' : 'a', '/clotures'),
                $linkCard('history', 'Journal d’audit', FinanceAudit::query()->count().' événement(s)', 'Exports, clôtures, consultations sensibles', 'Traçabilité', 'n', '/audit', 'audit'),
            ]),
        ];

        $byPromoter = Fin::group($ins->current, fn ($x) => $x['promoter']);
        $tables = [
            UI::table('apartment', 'Contribution par promoteur', $byPromoter->count().' promoteur(s) · '.mb_strtolower($ins->period['label']), ['Promoteur', 'Volume traité', 'Commission Y', 'Commission Z', 'Commissions nettes', 'En attente', 'Part du total'], $byPromoter->take(6)->map(fn (array $g): array => UI::row($g['key'], [
                UI::text($g['key'], true), UI::money($g['a']['vol']), UI::money($g['a']['comEncP']), UI::money($g['a']['comEncO']), UI::money($g['a']['comNet'], true), UI::money($g['a']['comPend'], false, true), UI::progress(Fin::ratio($g['a']['comNet'], $a['comNet'])),
            ], UI::link($ins->url('/promoteurs/'.rawurlencode($g['key']))), [['visibility', 'Analyse financière', UI::link($ins->url('/promoteurs/'.rawurlencode($g['key'])))]]))->all(), 1100, ['paid', 'Aucune commission', 'Aucune opération ne correspond à la période et aux filtres.']),
            $this->consistencyTable($ins, $consistency),
        ];

        return $this->render($ins, 'home', [
            ...$bar, 'alerts' => $alerts, 'kpis' => $kpis, 'chips' => $chips, 'charts' => $charts, 'flow' => $flow, 'tiles' => $tiles, 'panels' => $panels, 'tables' => $tables,
            'toolbar' => [...$this->viewTools($ins, ''), UI::button('Contrôle de cohérence', 'fact_check', UI::link($ins->url('/rapprochements')), 'primary')],
            'sheets' => $this->sheets($ins),
        ], 'Finance & Reporting', 'Centre financier O’CHANTIER · flux de commissions sur l’ensemble des opérations');
    }

    /**
     * Journal des commissions : chaque commission rattachée à son opération de paiement source.
     */
    public function journal(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('com')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter le journal des commissions.');
        }
        $showExcluded = (bool) ($ins->query['exclues'] ?? false);
        $sort = $ins->query['tri'] ?? 'date';
        $list = $ins->search($ins->current->filter(fn (array $x): bool => $showExcluded || $x['elig'] || $x['dup']))->sort(match ($sort) {
            'commission' => fn ($a, $b) => $b['com'] <=> $a['com'],
            'volume' => fn ($a, $b) => $b['gross'] <=> $a['gross'],
            'chantier' => fn ($a, $b) => strcoll($a['site'], $b['site']),
            default => fn ($a, $b) => [$b['d']['op'], $b['ref']] <=> [$a['d']['op'], $a['ref']],
        })->values();
        $s = FinanceLedger::aggregate($list);
        $consistent = $s['comEnc'] === $ins->a['comEnc'];
        $bar = $this->filterBar($ins, '/commissions', [$this->select($ins, 'tri', 'Tri', [['', 'date'], ['commission', 'commission'], ['volume', 'volume brut'], ['chantier', 'chantier']], 'Trier : ')]);
        $rule = PaymentLedger::config()['rule']['name'];
        $p = UI::paginate($list, (int) $request->query('page', 1), fn (array $x): array => UI::row($x['ref'], [
            UI::text($x['ref'], true), UI::text($x['opRef']), UI::text(Fin::date($x['d']['presta'])), UI::text(Fin::date($x['d']['pay'])), UI::text(Fin::date($x['d']['enc'])), UI::text($x['promoter']), UI::stacked($x['site'], $x['commune']),
            UI::text(Fin::workerName($x)), UI::text($x['prestation']), UI::money($x['gross']), UI::badge($x['srcCom'], $x['srcCom'] === 'Partagée' ? 'p' : 'b'), UI::text('Montant brut'), UI::text($rule), UI::text($x['ruleVersion']),
            UI::text("Y {$x['y']} % · Z {$x['z']} %"), UI::money($x['com'], true), UI::money($x['comEnc'], false, true), UI::money($x['comRef'], false, true), UI::money($x['comExt'], false, true), UI::money($x['comNet'], true),
            UI::badge($x['stShow'], self::COMMISSION_TONES[$x['stShow']] ?? 'n'), UI::badge($x['recSt'], self::RECONCILIATION_TONES[$x['recSt']] ?? 'n'),
        ], UI::link($ins->url("/commissions/{$x['ref']}")), array_filter([['visibility', 'Détail de la commission', UI::link($ins->url("/commissions/{$x['ref']}"))], $x['paymentRef'] ? ['receipt_long', 'Voir le paiement', self::paymentLink($x)] : null])));

        return $this->render($ins, 'commissions', [
            ...$bar, 'search' => true,
            'tiles' => [
                UI::tile('Entrées affichées', $list->count(), 'receipt_long', 'b', ['sub' => "{$s['exclDup']} doublon(s) · {$s['excl']} non éligible(s) · {$s['exclTest']} test(s)"]),
                UI::tile('Commission brute', Fin::money($s['comGross']), 'functions', 'b'), UI::tile('Encaissée', Fin::money($s['comEnc']), 'paid', 'g'), UI::tile('Remboursée', Fin::money($s['comRef']), 'undo', 'o'),
                UI::tile('Extournée', Fin::money($s['comExt']), 'block', 'r'), UI::tile('Commission nette', Fin::money($s['comNet']), 'savings', 'g'), UI::tile('En attente', Fin::money($s['comPend']), 'schedule', 'a'),
                UI::tile('Contrôle', $ins->q !== '' ? 'Recherche active' : ($consistent ? 'Conforme au KPI' : 'Écart '.Fin::money($s['comEnc'] - $ins->a['comEnc'])), 'fact_check', $ins->q !== '' || $consistent ? 'g' : 'r', ['sub' => 'Σ encaissé du journal = carte « Commissions encaissées »']),
            ],
            'toolbar' => [
                UI::button($showExcluded ? 'Masquer les exclues' : 'Afficher tests, annulées et non validées', $showExcluded ? 'visibility_off' : 'visibility', UI::link($ins->url('/commissions', array_filter(['exclues' => $showExcluded ? null : '1', 'tri' => $ins->query['tri'] ?? null, 'q' => $ins->query['q'] ?? null])))),
                $this->viewTools($ins, '/commissions')[0],
                UI::button('Exporter le journal', 'download', $this->generate($ins, 'commissions', 'Excel'), 'primary'),
            ],
            'tables' => [UI::table('receipt_long', "Journal des commissions · {$ins->period['range']}", $p['label'], FinanceReports::JOURNAL_COLUMNS, $p['rows'], 3000, ['search_off', 'Aucun résultat', 'Aucune commission ne correspond à la recherche, à la période ou aux filtres.'], $p['pagination'])],
        ], 'Journal des commissions', 'Chaque commission est rattachée à une opération de paiement source · totaux reproductibles', [['Journal des commissions']]);
    }

    /**
     * Événement de journal affichable (journal d’une opération de paiement ou audit Finance).
     *
     * @return array<string, mixed>
     */
    public static function activity(PaymentActivity|FinanceAudit $e): array
    {
        [$tone, $icon] = match ($e->category) {
            'creation' => ['info', 'add_circle'],
            'validation' => ['success', 'verified'],
            'paiement' => ['orange', 'payments'],
            'document' => ['info', 'download'],
            'statut' => ['purple', 'lock'],
            'controle' => ['neutral', 'visibility'],
            'diffusion' => ['info', 'share'],
            'anomalie' => ['danger', 'report'],
            default => ['purple', 'edit'],
        };
        $labels = ['creation' => 'Création', 'controle' => 'Consultation', 'document' => 'Export', 'statut' => 'Clôture', 'diffusion' => 'Diffusion', 'anomalie' => 'Anomalie'];

        return [
            'id' => $e->getTable().'-'.$e->id, 'date' => $e->occurred_at->format('Y-m-d'), 'time' => $e->occurred_at->format('H:i'), 'author' => $e->author,
            'category' => ['value' => $e->category, 'label' => $labels[$e->category] ?? ucfirst($e->category), 'tone' => $tone, 'icon' => $icon],
            'action' => $e->action, 'oldValue' => $e->old_value, 'newValue' => $e->new_value, 'comment' => $e->comment, 'origin' => $e->origin,
        ];
    }

    /**
     * Détail d’une commission : montants, règle, statuts, parcours de l’opération et journal.
     */
    public function commission(Request $request, string $reference): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('com')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les commissions.');
        }
        $x = $ins->ledger->find($reference);
        abort_unless($x !== null, 404);
        FinanceAudit::record('a consulté le détail d’une commission', 'Journal des commissions', null, $x['ref'], null, 'controle');
        $cfg = config('admin.finance');
        $comm = (int) round($x['comNet'] * $cfg['sales_cost_rate'] / 100);
        $margin = $x['comNet'] - $x['feeOut'] - $comm - $x['feeLost'] - ($x['ok'] ? $cfg['direct_cost'] : 0);
        $rule = PaymentLedger::config()['rule'];
        $d = fn (?string $v): string => Fin::date($v);
        $state = fn (?string $date, string $fail = ''): string => $date ? 'done' : ($fail ? 'fail' : 'todo');
        $alerts = [];
        if ($x['dup'] || $x['test'] || ! $x['valid']) {
            $alerts[] = UI::alert($x['dup'] ? 'danger' : 'info', $x['dup'] ? 'content_copy' : 'science', $x['dup'] ? 'Doublon exclu des KPI' : ($x['test'] ? 'Opération de test' : 'Calcul non validé'),
                $x['dup'] ? $x['dupWhy'].($x['dupOk'] ? ' · exclusion validée' : ' · à confirmer') : ($x['test'] ? 'Cette opération n’alimente pas les KPI réels.' : 'La commission reste estimée tant que le calcul n’est pas validé dans Paiements.'),
                $x['dup'] ? 'Rapprochements' : null, $x['dup'] ? UI::link($ins->url('/rapprochements')) : null);
        }
        $timeline = $x['paymentRef']
            ? PaymentActivity::query()->whereHas('payment', fn ($q) => $q->where('reference', $x['paymentRef']))->latest('occurred_at')->latest('id')->get()->map(fn (PaymentActivity $e): array => self::activity($e))->all()
            : array_values(array_filter([
                $x['d']['enc'] ? ['id' => 'enc', 'date' => $x['d']['enc'], 'time' => '14:40', 'author' => $x['provider'], 'category' => ['value' => 'validation', 'label' => 'Validation', 'tone' => 'success', 'icon' => 'verified'], 'action' => 'a confirmé l’encaissement', 'oldValue' => null, 'newValue' => Fin::money($x['received']), 'comment' => $x['extRef'], 'origin' => 'Système'] : null,
                ['id' => 'gen', 'date' => $x['d']['op'], 'time' => '23:59', 'author' => 'Système', 'category' => ['value' => 'creation', 'label' => 'Création', 'tone' => 'info', 'icon' => 'add_circle'], 'action' => 'a généré la commission', 'oldValue' => null, 'newValue' => Fin::money($x['com']), 'comment' => "{$x['opRef']} · règle {$x['ruleVersion']}", 'origin' => 'Système'],
            ]));
        $export = Fin::can('export') ? UI::download($ins->url("/commissions/{$x['ref']}/export")) : UI::deny('export');

        return $this->render($ins, 'commission', [
            'alerts' => $alerts,
            'tiles' => [
                UI::tile('Commission brute', Fin::money($x['com']), 'functions', 'b', ['sub' => 'Y '.Fin::money($x['comP']).' · Z '.Fin::money($x['comO'])]),
                UI::tile('Commission encaissée', Fin::money($x['comEnc']), 'paid', 'g'), UI::tile('Remboursement', Fin::money($x['comRef']), 'undo', 'o'), UI::tile('Extourne', Fin::money($x['comExt']), 'block', 'r'),
                UI::tile('Commission nette', Fin::money($x['comNet']), 'savings', 'g'),
                UI::tile('Frais opérateurs', Fin::can('cout') ? Fin::money($x['feeIn'] + $x['feeOut']) : Fin::HIDDEN, 'swap_horiz', 'o', ['sub' => 'Encaissement '.Fin::money($x['feeIn']).' (refacturé) · reversement '.Fin::money($x['feeOut']).' (supporté)']),
                UI::tile('Marge contributive', Fin::can('marge') ? Fin::money($margin) : Fin::HIDDEN, 'trending_up', $margin >= 0 ? 'g' : 'r', ['sub' => 'Nette − frais supportés − coûts commerciaux − remboursements supportés − coûts directs']),
            ],
            'flow' => ['steps' => [
                UI::step('Prestation', $d($x['d']['presta']), $state($x['d']['presta']), 'construction'),
                UI::step('Opération', $d($x['d']['op']), $state($x['d']['op']), 'receipt'),
                UI::step('Paiement', $x['d']['pay'] ? $d($x['d']['pay']) : $x['payStatus'], $state($x['d']['pay'], $x['payStatus'] === 'Échoué' ? 'fail' : ''), 'credit_card'),
                UI::step('Encaissement', $x['d']['enc'] ? $d($x['d']['enc']) : 'Non encaissé', $state($x['d']['enc']), 'account_balance_wallet'),
                UI::step('Reversement', $x['d']['rev'] ? $d($x['d']['rev']) : $x['revSt'], $state($x['d']['rev'], $x['revSt'] === 'Échoué' ? 'fail' : ''), 'send'),
                UI::step('Rapprochement', $x['d']['rec'] ? $d($x['d']['rec']) : $x['recSt'], $x['d']['rec'] || $x['recFixed'] ? 'done' : ($x['recSt'] === 'Avec écart' ? 'fail' : 'todo'), 'fact_check'),
            ], 'stacks' => []],
            'panels' => [
                UI::keyValues('receipt_long', 'Opération d’origine', [
                    ['Paiement source', $x['opRef'].($x['archived'] ? ' · archives clôturées' : '')], ['Référence externe', $x['extRef']], ['Type d’opération', $x['type']], ['Type de prestation', $x['prestation']], ['Promoteur', $x['promoter']],
                    ['Chantier', "{$x['site']} · {$x['commune']}"], ['Ouvrier', $x['workerSlug'] ? Fin::workerName($x)." · {$x['trade']}" : '—'], ['Période de prestation', $x['days'] ? "{$x['days']} jour(s) · ".$d($x['d']['presta']) : $d($x['d']['presta'])],
                    ['Moyen de paiement', "{$x['method']} · {$x['provider']}"], ['Responsable', $x['responsible']],
                ]),
                UI::keyValues('rule', 'Règle et calcul', [
                    ['Base de calcul', Fin::money($x['gross']).' (montant brut'.($x['days'] ? " : {$x['days']} j × ".Fin::money($x['rate']) : '').')'], ['Règle', $rule['name']], ['Version', $x['ruleVersion']], ['Taux', "Y {$x['y']} % · Z {$x['z']} %"],
                    ['Minimum', $rule['min'] ? Fin::money($rule['min']) : 'Aucun'], ['Maximum', $rule['max'] ? Fin::money($rule['max']) : 'Aucun'], ['Part promoteur', Fin::money($x['comP'])], ['Part ouvrier', Fin::money($x['comO'])],
                    ['Calcul', Fin::money($x['gross']).' × '.($x['y'] + $x['z']).' % = '.Fin::money($x['com'])], ['Total promoteur', Fin::money($x['promoterTotal']).' · reçu '.Fin::money($x['received'])],
                ]),
                UI::keyValues('flag', 'Statuts et dates', [
                    ['Commission', $x['stShow']], ['Paiement', $x['payStatus']], ['Reversement', $x['revSt']], ['Rapprochement', $x['recSt']], ['Date de prestation', $d($x['d']['presta'])], ['Date d’opération', $d($x['d']['op'])],
                    ['Date de paiement', $d($x['d']['pay'])], ['Date d’encaissement', $d($x['d']['enc'])], ['Date de reversement', $d($x['d']['rev'])], ['Date de rapprochement', $d($x['d']['rec'])],
                ]),
            ],
            'timeline' => $timeline,
            'toolbar' => array_values(array_filter([
                $x['paymentRef'] ? UI::button('Voir le paiement', 'receipt_long', self::paymentLink($x), 'primary') : null,
                $x['promoterSlug'] ? UI::button('Voir le promoteur', 'apartment', UI::link("/promoteurs/{$x['promoterSlug']}")) : null,
                $x['siteSlug'] ? UI::button('Voir le chantier', 'construction', UI::link("/chantiers/{$x['siteSlug']}")) : null,
                $x['workerSlug'] && Fin::can('ouv') ? UI::button('Voir l’ouvrier', 'engineering', UI::link("/ouvriers/{$x['workerSlug']}")) : null,
                UI::button('Voir la règle', 'rule', UI::rates()),
                UI::button('Signaler un écart', 'report', UI::reason('Signaler un écart', "{$x['ref']} · {$x['opRef']} — le signalement est transmis au responsable paiements et tracé dans l’audit.", 'report', 'Signaler', "/finance-reporting/commissions/{$x['ref']}/ecart", 'Description de l’écart', 'warning')),
                UI::button('Exporter le détail', 'download', $export),
            ])),
        ], "Commission {$x['ref']}", "{$x['srcCom']} · {$x['site']} · {$x['promoter']}", [['Journal des commissions', $ins->url('/commissions')], [$x['ref']]]);
    }

    /**
     * Analyses par chantier, promoteur, ouvrier, métier ou zone géographique.
     */
    public function analysis(Request $request, string $dimension): Response
    {
        $ins = $this->insights($request);
        [$title, $one, $icon, $right, $report] = self::DIMENSIONS[$dimension];
        $axis = array_key_exists($ins->query['axe'] ?? '', self::AXES) ? $ins->query['axe'] : 'commune';
        if ($dimension === 'geographie') {
            $one = mb_strtolower(self::AXES[$axis]);
        }
        if (! Fin::can($right)) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ('.Fin::role().") ne permet pas de consulter les données par {$one}.");
        }
        if ($dimension === 'ouvriers') {
            FinanceAudit::record('a consulté les données ouvriers', 'Par ouvrier', null, null, 'Données sensibles', 'controle');
        }
        $a = $ins->a;
        $t = FinanceLedger::today()->format('Y-m-d');
        $key = fn (array $x): string => (string) match ($dimension) {
            'chantiers' => $x['siteSlug'],
            'promoteurs' => $x['promoter'],
            'ouvriers' => $x['workerSlug'],
            'metiers' => $x['trade'],
            default => Fin::filterValue($axis, $x),
        };
        $label = fn (array $g): string => match ($dimension) {
            'chantiers' => $g['first']['site'],
            'ouvriers' => Fin::workerName($g['first']),
            default => $g['key'],
        };
        $base = $dimension === 'ouvriers' ? $ins->current->filter(fn (array $x): bool => (bool) $x['workerSlug']) : $ins->current;
        $groups = Fin::group($base, $key);
        $all = $groups->sum(fn (array $g): int => $g['a']['comNet']);
        $segments = [
            'chantiers' => [['', 'Tous les chantiers'], ['top', 'Top 10 chantiers'], ['def', 'Chantiers déficitaires'], ['cre', 'Créances élevées'], ['ref', 'Remboursements élevés'], ['ins', 'Données insuffisantes']],
            'promoteurs' => [['', 'Tous les promoteurs'], ['top', 'Plus contributeurs'], ['rent', 'Plus rentables'], ['ret', 'Avec retards'], ['lit', 'Avec litiges'], ['ina', 'Inactifs (30 j)'], ['new', 'Nouveaux (60 j)']],
        ][$dimension] ?? null;
        $segment = $segments ? (string) ($ins->query['segment'] ?? '') : '';
        if ($segment !== '') {
            $average = $groups->count() ? $groups->avg(fn (array $g): int => $g['a']['creance']) : 0;
            $operations = fn (string $promoter): Collection => $ins->scoped->where('promoter', $promoter)->pluck('d.op')->sort()->values();
            $groups = match ($segment) {
                'top' => $groups->take($dimension === 'chantiers' ? 10 : 5),
                'def' => $groups->filter(fn (array $g): bool => $g['a']['nOk'] && $g['a']['marge'] < 0),
                'cre' => $groups->filter(fn (array $g): bool => $g['a']['creance'] > 0 && $g['a']['creance'] >= $average)->sortByDesc(fn (array $g): int => $g['a']['creance']),
                'ref' => $groups->filter(fn (array $g): bool => $g['a']['refAmt'] > 0)->sortByDesc(fn (array $g): int => $g['a']['refAmt']),
                'ins' => $groups->filter(fn (array $g): bool => ! $g['a']['nOk']),
                'rent' => $groups->sortByDesc(fn (array $g): int => $g['a']['marge'])->take(5),
                'ret' => $groups->filter(fn (array $g): bool => $g['entries']->contains(fn (array $x): bool => $x['elig'] && ! $x['paid'] && $x['due'] < $t)),
                'lit' => $groups->filter(fn (array $g): bool => $ins->hasDispute($g['key'])),
                'ina' => $groups->filter(fn (array $g): bool => FinanceLedger::days((string) $operations($g['key'])->last(), $t) > 30),
                'new' => $groups->filter(fn (array $g): bool => FinanceLedger::days((string) $operations($g['key'])->first(), $t) <= 60),
                default => $groups,
            };
        }
        $sort = (string) ($ins->query['tri'] ?? '');
        $sortKey = match ($sort) {
            'volume' => 'vol', 'marge' => 'marge', 'creances' => 'creance', 'net' => 'owe', 'attente' => 'revPend', 'commissions' => 'comNet',
            default => $dimension === 'ouvriers' ? 'owe' : 'comNet',
        };
        $groups = $ins->search($groups->values(), fn (array $g): string => $label($g).' '.$g['key'])->sortByDesc(fn (array $g): int => $g['a'][$sortKey])->values();
        $sorts = $dimension === 'ouvriers' ? [['', 'montant net'], ['attente', 'montant en attente'], ['commissions', 'commissions nettes']] : [['', 'commissions nettes'], ['volume', 'volume'], ['marge', 'marge contributive'], ['creances', 'créances']];
        $extra = [$this->select($ins, 'tri', 'Tri', $sorts, 'Trier : ')];
        if ($segments) {
            $extra[] = $this->select($ins, 'segment', 'Segment', $segments, 'Segment : ');
        }
        if ($dimension === 'geographie') {
            $extra[] = [...$this->select($ins, 'axe', 'Axe', array_map(fn (string $k, string $l): array => [$k === 'commune' ? '' : $k, mb_strtolower($l)], array_keys(self::AXES), self::AXES), 'Analyse par '), 'value' => $axis === 'commune' ? '' : $axis];
        }
        $bar = $this->filterBar($ins, "/{$dimension}", $extra);
        $total = $groups->sum(fn (array $g): int => $g['a']['comNet']);
        $full = $segment === '' && $ins->q === '';
        $ucOne = mb_strtoupper(mb_substr($one, 0, 1)).mb_substr($one, 1);
        $tiles = [
            UI::tile("{$ucOne}(s) affiché(e)s", $groups->count(), $icon, 'b'),
            UI::tile('Volume d’affaires', Fin::money($groups->sum(fn (array $g): int => $g['a']['vol'])), 'account_balance_wallet', 'b'),
            UI::tile('Commissions nettes', Fin::money($total), 'savings', 'g', ['bar' => Fin::ratio($total, $a['comNet']) ?? 0, 'sub' => Fin::percent(Fin::ratio($total, $a['comNet'])).' du total de la période']),
            UI::tile('Contrôle des totaux', $full ? ($all === $a['comNet'] ? 'Conforme au total' : ($dimension === 'ouvriers' ? 'Hors acomptes' : 'Écart '.Fin::money($a['comNet'] - $all))) : 'Sélection partielle', 'fact_check', $full && ($all === $a['comNet'] || $dimension === 'ouvriers') ? 'g' : 'n', ['sub' => 'Σ des lignes = commissions nettes de la vue d’ensemble'.($dimension === 'ouvriers' ? ' (acomptes chantier exclus)' : '')]),
        ];
        if ($dimension === 'chantiers') {
            $tiles[] = UI::tile('Chantiers déficitaires', $groups->filter(fn (array $g): bool => $g['a']['nOk'] && $g['a']['marge'] < 0)->count(), 'money_off', 'r');
            $tiles[] = UI::tile('Données insuffisantes', $groups->filter(fn (array $g): bool => ! $g['a']['nOk'])->count(), 'help', 'n');
        }
        $monthly = $ins->byMonth($dimension === 'ouvriers' ? $ins->scoped->filter(fn (array $x): bool => (bool) $x['workerSlug']) : $ins->scoped, fn (array $g): int => $g['comNet']);
        $charts = [
            UI::chart($dimension === 'geographie' ? 'Commissions par '.mb_strtolower(self::AXES[$axis]) : 'Classement · top '.min(6, $groups->count()), "Commissions nettes · {$ins->period['range']} · FCFA", $groups->take(6)->map(fn (array $g): array => [implode(' ', array_slice(explode(' ', $label($g)), 0, 2)), $g['a']['comNet'], Fin::thousands($g['a']['comNet'])])->all(), $dimension === 'geographie' ? 'var(--c-accent-600)' : null),
            UI::chart('Évolution mensuelle', 'Commissions nettes · 12 derniers mois · FCFA · filtres actifs', array_map(fn (array $m): array => [$m[0], $m[1], Fin::thousands($m[1])], $monthly)),
        ];
        $hide = fn (string $r, int|float|null $v): array => UI::text(Fin::can($r) ? Fin::money($v) : Fin::HIDDEN);
        $result = fn (array $g): array => Fin::can('marge') ? UI::badge(...self::result($g['a'])) : UI::badge('Accès restreint', 'n');
        $previous = $dimension === 'metiers' ? Fin::group($ins->previous, $key)->keyBy('key') : collect();
        [$columns, $cells] = match ($dimension) {
            'chantiers' => [['Chantier', 'Volume d’affaires', 'Opérations', 'Encaissé', 'Com. promoteur', 'Com. ouvrier', 'Com. brutes', 'Com. nettes', 'Frais opérateurs', 'Remboursements', 'Reversé', 'À reverser', 'Marge contributive', 'Taux de marge', 'Créances', 'Rapprochement', 'Rentabilité'], fn (array $g): array => [
                UI::stacked($g['first']['site'], "{$g['first']['promoter']} · {$g['first']['commune']}"), UI::money($g['a']['vol']), UI::text((string) $g['a']['nOk']), UI::money($g['a']['recv']), UI::money($g['a']['comEncP']), UI::money($g['a']['comEncO']), UI::money($g['a']['comGross']), UI::money($g['a']['comNet'], true),
                $hide('cout', $g['a']['fees']), UI::money($g['a']['refAmt']), UI::money($g['a']['revDone']), UI::money($g['a']['revPend']), $hide('marge', $g['a']['marge']), UI::text(Fin::can('marge') ? Fin::percent(Fin::ratio($g['a']['marge'], $g['a']['comNet'])) : Fin::HIDDEN),
                UI::money($g['a']['creance']), UI::progress(Fin::ratio($g['a']['recOk'], $g['a']['recElig'])), $result($g),
            ]],
            'promoteurs' => [['Promoteur', 'Chantiers', 'Volume d’affaires', 'Paiements attendus', 'Paiements reçus', 'Créances', 'Ancienneté des créances', 'Com. générées', 'Com. encaissées', 'Remboursements', 'Taux de remboursement', 'Délai moyen de paiement', 'Marge contributive', 'Rapprochement', 'Niveau de risque'], function (array $g) use ($ins, $hide, $t): array {
                $unpaid = $g['entries']->filter(fn (array $x): bool => $x['elig'] && ! $x['paid']);
                $age = $unpaid->isNotEmpty() ? $unpaid->max(fn (array $x): int => FinanceLedger::days($x['due'], $t)) : null;

                return [
                    UI::text($g['key'], true), UI::text((string) $g['entries']->pluck('siteSlug')->unique()->count()), UI::money($g['a']['vol']), UI::money($g['a']['recv'] + $g['a']['creance']), UI::money($g['a']['recv']), UI::money($g['a']['creance']),
                    $age === null ? UI::text('—', false, true) : UI::text($age < 0 ? 'Non échu' : "{$age} j"), UI::money($g['a']['comGross']), UI::money($g['a']['comEnc'], true), UI::money($g['a']['refAmt']), UI::text(Fin::percent(Fin::ratio($g['a']['refAmt'], $g['a']['recv']))),
                    UI::text(Fin::daysText($g['a']['avgEnc'])), $hide('marge', $g['a']['marge']), UI::progress(Fin::ratio($g['a']['recOk'], $g['a']['recElig'])), UI::badge(...$this->risk($ins, $g)),
                ];
            }],
            'ouvriers' => [['Ouvrier', 'Métier', 'Chantiers', 'Montant brut gagné', 'Commission supportée', 'Bonus et indemnités', 'Retenues', 'Montant net', 'Reversé', 'En attente', 'Reversements échoués', 'Délai moyen', 'Prestations'], function (array $g): array {
                $eligible = $g['entries']->where('elig', true);

                return [
                    UI::person(Fin::workerName($g['first']), $g['first']['workerCode']), UI::text($g['first']['trade']), UI::text((string) $g['entries']->pluck('siteSlug')->unique()->count()), UI::money($g['a']['brut']), UI::money($g['a']['comO']),
                    UI::money($eligible->sum('bonus')), UI::money($eligible->sum('deduction')), UI::money($g['a']['owe'], true), UI::money($g['a']['revDone']), UI::money($g['a']['revPend']),
                    $g['a']['revFailN'] ? UI::text("{$g['a']['revFailN']} · ".Fin::money($g['a']['revFail'])) : UI::text('—', false, true), UI::text(Fin::daysText($g['a']['avgRev'])), UI::text($eligible->sum('days').' j'),
                ];
            }],
            'metiers' => [['Métier', 'Volume d’affaires', 'Ouvriers', 'Opérations', 'Panier moyen', 'Commission moyenne', 'Com. nettes', 'Frais opérateurs', 'Remboursements', 'Marge contributive', 'Évolution', 'Part du volume'], function (array $g) use ($ins, $previous, $hide, $a): array {
                $before = $previous->get($g['key'])['a']['comNet'] ?? 0;
                $trend = match (true) {
                    ! $ins->period['comparable'] => 'Comparaison indisponible',
                    ! $before => $g['a']['comNet'] ? 'Nouvelle activité' : '—',
                    default => (($g['a']['comNet'] - $before) / $before >= 0 ? '+' : '− ').str_replace('.', ',', (string) abs(round(($g['a']['comNet'] - $before) / $before * 100, 1))).' %',
                };

                return [
                    UI::text($g['key'], true), UI::money($g['a']['vol']), UI::text((string) $g['entries']->pluck('workerSlug')->filter()->unique()->count()), UI::text((string) $g['a']['nOk']),
                    UI::money($g['a']['nOk'] ? $g['a']['volEnc'] / $g['a']['nOk'] : 0), UI::money($g['a']['nComNet'] ? $g['a']['comNet'] / $g['a']['nComNet'] : 0), UI::money($g['a']['comNet'], true), $hide('cout', $g['a']['fees']), UI::money($g['a']['refAmt']), $hide('marge', $g['a']['marge']),
                    UI::badge($trend, str_starts_with($trend, '+') || $trend === 'Nouvelle activité' ? 'g' : (str_starts_with($trend, '−') ? 'o' : 'n')), UI::progress(Fin::ratio($g['a']['vol'], $a['vol'])),
                ];
            }],
            default => [[self::AXES[$axis], 'Volume traité', 'Commissions nettes', 'Opérations', 'Ouvriers actifs', 'Promoteurs actifs', 'Chantiers actifs', 'Panier moyen', 'Taux de remboursement', 'Marge contributive'], fn (array $g): array => [
                UI::place($g['key']), UI::money($g['a']['vol']), UI::money($g['a']['comNet'], true), UI::text((string) $g['a']['nOk']), UI::text((string) $g['entries']->pluck('workerSlug')->filter()->unique()->count()),
                UI::text((string) $g['entries']->pluck('promoter')->unique()->count()), UI::text((string) $g['entries']->pluck('siteSlug')->unique()->count()), UI::money($g['a']['nOk'] ? $g['a']['volEnc'] / $g['a']['nOk'] : 0),
                UI::text(Fin::percent(Fin::ratio($g['a']['refAmt'], $g['a']['recv']))), $hide('marge', $g['a']['marge']),
            ]],
        };
        $open = fn (array $g): array => in_array($dimension, ['chantiers', 'promoteurs', 'ouvriers'], true)
            ? UI::link($ins->url("/{$dimension}/".rawurlencode($g['key'])))
            : UI::link($ins->url('/commissions', [$dimension === 'metiers' ? 'metier' : $axis => $g['key']]));
        $p = UI::paginate($groups, (int) $request->query('page', 1), fn (array $g): array => UI::row($g['key'], $cells($g), $open($g), array_values(array_filter([
            ['visibility', 'Analyse détaillée', $open($g)],
            $dimension === 'chantiers' ? ['open_in_new', 'Fiche chantier', UI::link("/chantiers/{$g['key']}")] : null,
        ]))));
        $crumb = ['chantiers' => 'Par chantier', 'promoteurs' => 'Par promoteur', 'ouvriers' => 'Par ouvrier', 'metiers' => 'Par métier', 'geographie' => 'Géographie'][$dimension];

        return $this->render($ins, 'analysis', [
            ...$bar, 'dimension' => $dimension, 'search' => true, 'tiles' => $tiles, 'charts' => $charts,
            'toolbar' => [$this->viewTools($ins, "/{$dimension}")[0], UI::button('Exporter l’analyse', 'download', $this->generate($ins, $report, 'Excel'), 'primary')],
            'tables' => [UI::table($icon, "{$title} · ".mb_strtolower($ins->period['label']), "{$p['label']} · total ".Fin::money($total), $columns, $p['rows'], ['chantiers' => 2600, 'promoteurs' => 2300][$dimension] ?? 1700, ['search_off', 'Aucun résultat', 'Aucune donnée ne correspond à la période, au segment ou aux filtres.'], $p['pagination'])],
        ], $title, [
            'chantiers' => 'Contribution et rentabilité de chaque chantier · résultat contributif = commissions nettes − coûts attribués',
            'promoteurs' => 'Encaissements, créances, contribution et risque par promoteur',
            'ouvriers' => 'Rémunérations, commissions supportées et reversements · données sensibles',
            'metiers' => 'Volume, panier moyen et contribution par métier',
            'geographie' => 'Volume et contribution par ville, commune, zone ou agence',
        ][$dimension], [[$crumb]]);
    }

    /**
     * Résultat contributif : libellé et ton.
     *
     * @param  array<string, mixed>  $a
     * @return array{0: string, 1: string}
     */
    private static function result(array $a): array
    {
        return match (true) {
            ! $a['nOk'] => ['Données insuffisantes', 'n'],
            $a['marge'] > 0 => ['Résultat positif', 'g'],
            $a['marge'] === 0 => ['Résultat nul', 'a'],
            default => ['Résultat négatif', 'r'],
        };
    }

    /**
     * Niveau de risque d’un promoteur : retards, créances anciennes, remboursements, litiges ouverts.
     *
     * @param  array<string, mixed>  $g
     * @return array{0: string, 1: string}
     */
    private function risk(Fin $ins, array $g): array
    {
        $t = FinanceLedger::today()->format('Y-m-d');
        $unpaid = $g['entries']->filter(fn (array $x): bool => $x['elig'] && ! $x['paid']);
        $score = ($unpaid->contains(fn (array $x): bool => FinanceLedger::days($x['due'], $t) > 7) ? 1 : 0)
            + ($unpaid->contains(fn (array $x): bool => FinanceLedger::days($x['due'], $t) > 30) ? 1 : 0)
            + ((Fin::ratio($g['a']['refAmt'], $g['a']['recv']) ?? 0) > config('admin.finance.thresholds.refunds') ? 1 : 0)
            + ($ins->hasOpenDispute($g['key']) ? 2 : 0);

        return $score >= 3 ? ['Élevé', 'r'] : ($score >= 1 ? ['Modéré', 'a'] : ['Faible', 'g']);
    }

    /**
     * Fiche financière d’un chantier, d’un promoteur ou d’un ouvrier.
     */
    public function analysisDetail(Request $request, string $dimension, string $key): Response
    {
        $ins = $this->insights($request);
        [$title, $one, , $right, $report] = self::DIMENSIONS[$dimension];
        if (! Fin::can($right)) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ('.Fin::role().") ne permet pas de consulter les données par {$one}.");
        }
        $match = fn (array $x): bool => match ($dimension) {
            'chantiers' => $x['siteSlug'] === $key,
            'promoteurs' => $x['promoter'] === $key,
            default => $x['workerSlug'] === $key,
        };
        $first = $ins->scoped->first($match) ?? $ins->all->first($match);
        abort_unless($first !== null, 404);
        $current = $ins->current->filter($match)->values();
        $g = FinanceLedger::aggregate($current);
        $name = match ($dimension) {
            'chantiers' => $first['site'],
            'promoteurs' => $first['promoter'],
            default => Fin::workerName($first),
        };
        $months = $ins->byMonth($ins->scoped->filter($match), fn (array $a): array => $a);
        $crumb = ['chantiers' => 'Par chantier', 'promoteurs' => 'Par promoteur', 'ouvriers' => 'Par ouvrier'][$dimension];
        $searchTerm = match ($dimension) {
            'chantiers' => $first['site'],
            'promoteurs' => $first['promoter'],
            default => $first['worker'],
        };

        return $this->render($ins, 'analysis', [
            'dimension' => $dimension,
            'tiles' => [
                UI::tile('Volume d’affaires', Fin::money($g['vol']), 'account_balance_wallet', 'b', ['sub' => "{$g['nOk']} opération(s) réussie(s)"]),
                UI::tile('Encaissé', Fin::money($g['recv']), 'paid', 'g'),
                UI::tile($dimension === 'ouvriers' ? 'Commission supportée (Z)' : 'Commissions nettes', Fin::money($dimension === 'ouvriers' ? $g['comEncO'] : $g['comNet']), 'savings', 'g', ['sub' => 'Y '.Fin::money($g['comEncP']).' · Z '.Fin::money($g['comEncO'])]),
                UI::tile('Frais opérateurs', Fin::can('cout') ? Fin::money($g['fees']) : Fin::HIDDEN, 'swap_horiz', 'o'),
                UI::tile('Remboursements', Fin::money($g['refAmt']), 'undo', 'o'),
                UI::tile($dimension === 'ouvriers' ? 'Net reversé' : 'Reversé aux ouvriers', Fin::money($g['revDone']), 'send', 'b', ['sub' => 'À reverser '.Fin::money($g['revPend'])]),
                UI::tile('Créances', Fin::money($g['creance']), 'request_quote', $g['creEch'] ? 'a' : 'n', ['sub' => 'Échu '.Fin::money($g['creEch'])]),
                UI::tile('Marge contributive', Fin::can('marge') ? Fin::money($g['marge']) : Fin::HIDDEN, 'trending_up', $g['marge'] >= 0 ? 'g' : 'r', ['sub' => Fin::can('marge') ? self::result($g)[0].' · '.Fin::percent(Fin::ratio($g['marge'], $g['comNet'])) : 'Accès restreint']),
                UI::tile('Taux de rapprochement', Fin::percent(Fin::ratio($g['recOk'], $g['recElig'])), 'fact_check', 'b'),
            ],
            'charts' => [
                UI::chart('Évolution mensuelle des commissions', 'Commissions nettes · 12 derniers mois · FCFA', array_map(fn (array $m): array => [$m[0], $m[1]['comNet'], Fin::thousands($m[1]['comNet'])], $months)),
                UI::chart('Volume d’affaires mensuel', 'Volume net · 12 derniers mois · FCFA', array_map(fn (array $m): array => [$m[0], $m[1]['vol'], Fin::millions($m[1]['vol'])], $months), 'var(--c-accent-600)'),
            ],
            'toolbar' => array_values(array_filter([
                $dimension === 'chantiers' && $first['siteSlug'] ? UI::button('Fiche chantier', 'open_in_new', UI::link("/chantiers/{$first['siteSlug']}"), 'primary') : null,
                $dimension === 'promoteurs' && $first['promoterSlug'] ? UI::button('Fiche promoteur', 'open_in_new', UI::link("/promoteurs/{$first['promoterSlug']}"), 'primary') : null,
                $dimension === 'ouvriers' ? UI::button('Fiche ouvrier', 'open_in_new', UI::link("/ouvriers/{$first['workerSlug']}"), 'primary') : null,
                $dimension !== 'promoteurs' ? UI::button('Voir le promoteur', 'apartment', UI::link($ins->url('/promoteurs/'.rawurlencode($first['promoter'])))) : null,
                UI::button('Voir dans Paiements', 'credit_card', UI::link('/paiements?'.http_build_query(['periode' => 'annee', 'q' => $searchTerm]))),
                UI::button('Exporter', 'download', $this->generate($ins, $report, 'Excel')),
            ])),
            'tables' => [$this->commissionTable($ins, $current, (int) $request->query('page', 1))],
        ], $name, "{$title} · {$ins->period['range']}".match ($dimension) {
            'chantiers' => " · {$first['promoter']} · {$first['commune']}",
            'ouvriers' => " · {$first['trade']}",
            default => '',
        }, [[$crumb, $ins->url("/{$dimension}")], [$name]]);
    }

    /**
     * Créances promoteurs : encours à date, balance âgée et recouvrement.
     */
    public function receivables(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('cre')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les créances.');
        }
        $a = $ins->a;
        $st = $ins->stock;
        $cfg = config('admin.finance');
        $t = FinanceLedger::today()->format('Y-m-d');
        $unpaid = $ins->scoped->filter(fn (array $x): bool => $x['elig'] && ! $x['paid'])->map(function (array $x) use ($ins, $t): array {
            $age = FinanceLedger::days($x['due'], $t);
            $x['age'] = $age;
            $x['class'] = match (true) {
                $age < 0 => 'Non échu',
                $ins->hasOpenDispute($x['promoter']) => 'Litige',
                $age > 90 => 'Provisionné',
                $age > 7 => 'En retard',
                default => 'Échu',
            };

            return $x;
        })->values();
        $sum = fn (string $class): int => (int) $unpaid->where('class', $class)->sum('promoterTotal');
        $aging = FinanceLedger::aging($unpaid);
        $classes = ['Non échu' => 'g', 'Échu' => 'a', 'En retard' => 'o', 'Litige' => 'r', 'Provisionné' => 'r'];
        $bar = $this->filterBar($ins, '/creances');
        $balance = $ins->search(collect($aging['rows']), fn (array $r): string => $r['name']);
        $detail = UI::paginate($ins->search($unpaid)->sortByDesc('age')->values(), (int) $request->query('page', 1), fn (array $x): array => UI::row($x['ref'], [
            UI::text($x['opRef'], true), UI::text($x['promoter']), UI::text($x['site']), UI::text(Fin::date($x['d']['op'])), UI::text(Fin::date($x['due'])), UI::text($x['age'] < 0 ? 'Dans '.-$x['age'].' j' : "{$x['age']} j"),
            UI::money($x['promoterTotal'], true), UI::badge($x['payStatus'], $x['payStatus'] === 'Échoué' ? 'r' : 'a'), UI::badge($x['class'], $classes[$x['class']]),
        ], self::paymentLink($x) ?? UI::link($ins->url("/commissions/{$x['ref']}")), array_values(array_filter([['receipt_long', 'Voir la commission', UI::link($ins->url("/commissions/{$x['ref']}"))], $x['paymentRef'] ? ['credit_card', 'Voir le paiement', self::paymentLink($x)] : null]))));

        return $this->render($ins, 'creances', [
            ...$bar, 'search' => true, 'stockDate' => 'period',
            'tiles' => [
                UI::tile('Total à recevoir', Fin::money($st['creance']), 'request_quote', 'b', ['sub' => $unpaid->count().' opération(s)']),
                UI::tile('Non échu', Fin::money($sum('Non échu')), 'event_upcoming', 'g'), UI::tile('Échu', Fin::money($sum('Échu')), 'event', 'a', ['sub' => '0 à 7 jours après échéance']),
                UI::tile('En retard', Fin::money($sum('En retard')), 'warning', 'o'), UI::tile('Litige', Fin::money($sum('Litige')), 'gavel', 'r'),
                UI::tile('Irrécouvrable ou provisionné', Fin::money($sum('Provisionné')), 'block', 'r', ['sub' => 'Plus de '.end($cfg['aging']['receivables']).' jours']),
                UI::tile('Encaissé sur la période', Fin::money($a['recv']), 'paid', 'g'),
                UI::tile('Taux de recouvrement', Fin::percent(Fin::ratio($a['dueOk'], $a['dueTot'])), 'percent', 'b', ['bar' => Fin::ratio($a['dueOk'], $a['dueTot']) ?? 0, 'sub' => "Objectif {$cfg['objectives']['recovery']} %"]),
                UI::tile('Délai moyen d’encaissement', Fin::daysText($a['avgEnc']), 'timer', 'b', ['sub' => 'Échéance → encaissement']),
            ],
            'flow' => [
                'steps' => array_map(fn (string $l, int $v, int $i): array => UI::step($l, Fin::money($v), $i === 0 ? 'done' : ($i <= 1 ? 'cur' : ($i <= 3 ? 'warn' : 'fail')), $i === 0 ? 'event_upcoming' : 'hourglass_bottom'), $aging['labels'], $aging['totals'], array_keys($aging['labels'])),
                'stacks' => [
                    UI::stack('Balance âgée', 'Tranches configurées dans Paramètres · '.implode(' / ', $cfg['aging']['receivables']).' jours', array_map(fn (string $l, int $v): array => [$l, $v], $aging['labels'], $aging['totals'])),
                    UI::stack('Répartition par statut', 'Encours au '.FinanceLedger::today()->format('d/m/Y'), array_map(fn (string $c): array => [$c, $sum($c)], array_keys($classes))),
                ],
            ],
            'toolbar' => [$this->viewTools($ins, '/creances')[0], UI::button('Exporter la balance âgée', 'download', $this->generate($ins, 'balance', 'Excel')), UI::button('Rapport des créances', 'summarize', $this->generate($ins, 'creances', 'PDF'), 'primary')],
            'tables' => [
                UI::table('table_chart', 'Balance âgée par promoteur', $balance->count().' promoteur(s)', ['Promoteur', ...$aging['labels'], 'Total'], $balance->map(fn (array $r): array => UI::row($r['name'], [
                    UI::text($r['name'], true), ...array_map(fn (int $v): array => UI::money($v, false, true), $r['values']), UI::money($r['total'], true),
                ], UI::link($ins->url('/promoteurs/'.rawurlencode($r['name']))), [['visibility', 'Analyse promoteur', UI::link($ins->url('/promoteurs/'.rawurlencode($r['name'])))]]))->all(), 1300, ['task_alt', 'Aucune créance', 'Aucun montant à recevoir avec les filtres actifs.']),
                UI::table('request_quote', 'Détail des créances', $detail['label'], ['Opération', 'Promoteur', 'Chantier', 'Date d’opération', 'Échéance', 'Âge', 'Montant dû', 'Statut du paiement', 'Classement'], $detail['rows'], 1300, ['task_alt', 'Aucune créance', 'Aucun montant à recevoir.'], $detail['pagination']),
            ],
        ], 'Créances promoteurs', 'Montants validés restant à encaisser · balance âgée et recouvrement', [['Créances']]);
    }

    /**
     * Reversements aux ouvriers : dettes opérationnelles, ancienneté et délais.
     */
    public function payouts(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('rev')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les reversements.');
        }
        $a = $ins->a;
        $cfg = config('admin.finance');
        $b = $cfg['aging']['payouts'];
        $t = FinanceLedger::today()->format('Y-m-d');
        $labels = ['Moins de 24 h', "1 à {$b[1]} j", ($b[1] + 1)." à {$b[2]} j", ($b[2] + 1)." à {$b[3]} j", "Plus de {$b[3]} j"];
        $pending = $ins->scoped->filter(fn (array $x): bool => $x['ok'] && $x['workerSlug'] && $x['payoutStatus'] !== 'Réussi')->map(function (array $x) use ($b, $t): array {
            $x['age'] = $x['d']['enc'] ? FinanceLedger::days($x['d']['enc'], $t) : 0;
            $x['band'] = match (true) {
                $x['age'] < $b[0] => 0,
                $x['age'] <= $b[1] => 1,
                $x['age'] <= $b[2] => 2,
                $x['age'] <= $b[3] => 3,
                default => 4,
            };

            return $x;
        })->values();
        $totals = array_map(fn (int $i): int => (int) $pending->where('band', $i)->sum('net'), array_keys($labels));
        $sum = fn (string $status): int => (int) $pending->where('revSt', $status)->sum('net');
        $tones = ['Disponible' => 'g', 'Programmé' => 'b', 'En cours' => 'b', 'Échoué' => 'r', 'Suspendu' => 'o'];
        $p = UI::paginate($ins->search($pending)->sortByDesc('age')->values(), (int) $request->query('page', 1), fn (array $x): array => UI::row($x['ref'], [
            UI::text($x['opRef'], true), UI::text(Fin::workerName($x)), UI::text($x['site']), UI::text(Fin::date($x['d']['enc'])), UI::text("{$x['age']} j"), UI::text($labels[$x['band']]), UI::money($x['net'], true),
            UI::text($x['workerMethod'] ?: '—'), UI::badge($x['revSt'], $tones[$x['revSt']] ?? 'n'),
        ], self::paymentLink($x), $x['paymentRef'] ? [['credit_card', 'Voir le paiement', self::paymentLink($x)]] : []));

        return $this->render($ins, 'reversements', [
            ...$this->filterBar($ins, '/reversements'), 'search' => true, 'stockDate' => 'stock',
            'tiles' => [
                UI::tile('Total dû aux ouvriers', Fin::money($ins->stock['revPend']), 'account_balance', 'b', ['sub' => $pending->count().' reversement(s) · ce n’est pas un revenu']),
                UI::tile('Disponible au reversement', Fin::money($sum('Disponible')), 'savings', 'g'), UI::tile('Programmé', Fin::money($sum('Programmé')), 'event', 'b'), UI::tile('En cours', Fin::money($sum('En cours')), 'sync', 'b'),
                UI::tile('Reversé sur la période', Fin::money($a['revDone']), 'send', 'g'), UI::tile('Échoué', Fin::money($sum('Échoué')), 'error', 'r'), UI::tile('Suspendu', Fin::money($sum('Suspendu')), 'pause_circle', 'o'),
                UI::tile('Délai moyen de reversement', Fin::daysText($a['avgRev']), 'timer', $a['avgRev'] !== null && $a['avgRev'] > $cfg['objectives']['payout_delay'] ? 'o' : 'g', ['sub' => "Objectif {$cfg['objectives']['payout_delay']} j"]),
                UI::tile('Taux d’échec', Fin::percent(Fin::ratio($a['revFailN'], $a['revN'])), 'percent', 'o'),
            ],
            'flow' => [
                'steps' => array_map(fn (string $l, int $v, int $i): array => UI::step($l, Fin::money($v), [0 => 'done', 1 => 'cur', 2 => 'warn'][$i] ?? 'fail', 'schedule_send'), $labels, $totals, array_keys($labels)),
                'stacks' => [
                    UI::stack('Ancienneté des montants à reverser', 'Seuils configurés dans Paramètres', array_map(fn (string $l, int $v): array => [$l, $v], $labels, $totals)),
                    UI::stack('Statut des reversements', 'Encours au '.FinanceLedger::today()->format('d/m/Y'), array_map(fn (string $st): array => [$st, $sum($st)], array_keys($tones))),
                ],
            ],
            'toolbar' => [$this->viewTools($ins, '/reversements')[0], UI::button('Traiter dans Paiements', 'credit_card', UI::link('/paiements/a-reverser')), UI::button('Rapport des reversements', 'download', $this->generate($ins, 'reversements', 'Excel'), 'primary')],
            'tables' => [UI::table('send', 'Montants à reverser', $p['label'], ['Opération', 'Ouvrier', 'Chantier', 'Encaissé le', 'Ancienneté', 'Tranche', 'Net dû', 'Moyen', 'Statut'], $p['rows'], 1300, ['task_alt', 'Aucun reversement en attente', 'Tous les montants encaissés ont été reversés.'], $p['pagination'])],
        ], 'Reversements aux ouvriers', 'Dettes opérationnelles envers les ouvriers · ancienneté et délais', [['Reversements']]);
    }

    /**
     * Trésorerie opérationnelle : flux suivis par la plateforme (aucune connexion bancaire).
     */
    public function treasury(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('cout')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter la trésorerie opérationnelle.');
        }
        $a = $ins->a;
        $cover = Fin::ratio($ins->stock['fonds'], $ins->stock['revPend']);
        $threshold = config('admin.finance.thresholds.coverage');
        $bar = $this->filterBar($ins, '/tresorerie');
        $alerts = [...$bar['alerts'], UI::alert('info', 'account_balance', 'Aucune connexion bancaire', 'Aucun solde bancaire réel n’est disponible : seul le « Flux net opérationnel suivi » est affiché.')];
        if ($cover === null) {
            $alerts[] = UI::alert('warning', 'help', 'Couverture non calculable', 'Aucun montant exigible envers les ouvriers : le ratio de couverture ne peut pas être calculé (division par zéro évitée).');
        } elseif ($cover < $threshold) {
            $alerts[] = UI::alert('danger', 'warning', 'Couverture des reversements sous le seuil', Fin::percent($cover)." pour un seuil de {$threshold} %. Ce ratio est un indicateur de pilotage, pas une preuve de solvabilité réglementaire.", 'Reversements', UI::link($ins->url('/reversements')));
        }
        $months = $ins->byMonth($ins->scoped, fn (array $g): array => $g);
        $basis = mb_strtolower($ins->basisLabel());

        return $this->render($ins, 'tresorerie', [
            ...$bar, 'alerts' => $alerts,
            'tiles' => [
                UI::tile('Encaissements promoteurs', Fin::money($a['recv']), 'south_west', 'g'), UI::tile('Commissions conservées', Fin::money($a['comNet']), 'savings', 'g'),
                UI::tile('Montants à reverser', Fin::money($a['owe'] - $a['revDone']), 'account_balance', 'a'), UI::tile('Reversements effectués', '− '.Fin::money($a['revDone']), 'north_east', 'o'),
                UI::tile('Remboursements payés', '− '.Fin::money($a['refAmt']), 'undo', 'o'), UI::tile('Frais opérateurs payés', '− '.Fin::money($a['fees']), 'swap_horiz', 'o'),
                UI::tile('Flux net opérationnel suivi', Fin::money($a['flux']), 'water_drop', $a['flux'] >= 0 ? 'g' : 'r', ['sub' => 'Encaissements − reversements − remboursements − frais']),
                UI::tile('Couverture des reversements', $cover === null ? 'Non calculable' : Fin::percent($cover), 'shield', $cover === null ? 'n' : ($cover >= $threshold ? 'g' : 'r'), ['bar' => min(100, $cover ?? 0), 'sub' => "Seuil {$threshold} % · encours au ".FinanceLedger::today()->format('d/m/Y')]),
            ],
            'flow' => [
                'steps' => [
                    UI::step('Encaissements confirmés', Fin::money($a['recv']), 'done', 'south_west'),
                    UI::step('− Reversements effectués', Fin::money($a['revDone']), 'warn', 'send', UI::link($ins->url('/reversements'))),
                    UI::step('− Remboursements payés', Fin::money($a['refAmt']), 'warn', 'undo', UI::link($ins->url('/remboursements'))),
                    UI::step('− Frais opérateurs payés', Fin::money($a['fees']), 'warn', 'swap_horiz', UI::link($ins->url('/frais-operateurs'))),
                    UI::step('− Autres décaissements', '0 FCFA · aucun enregistré', 'todo', 'more_horiz'),
                    UI::step('= Flux net opérationnel suivi', Fin::money($a['flux']), $a['flux'] >= 0 ? 'cur' : 'fail', 'water_drop'),
                ],
                'stacks' => [UI::stack('Utilisation des encaissements', $ins->period['range'], [['Reversé aux ouvriers', $a['revDone']], ['Encore dû aux ouvriers', $a['owe'] - $a['revDone']], ['Commissions conservées', $a['comNet']], ['Frais opérateurs', $a['fees']], ['Remboursements', $a['refAmt']]])],
            ],
            'charts' => [
                UI::chart('Flux net opérationnel suivi', "Mensuel · 12 derniers mois · FCFA · base : {$basis}", array_map(fn (array $m): array => [$m[0], $m[1]['flux'], Fin::thousands($m[1]['flux'])], $months), 'var(--c-success)'),
                UI::chart('Encaissements', 'Mensuel · FCFA', array_map(fn (array $m): array => [$m[0], $m[1]['recv'], Fin::thousands($m[1]['recv'])], $months)),
                UI::chart('Reversements effectués', 'Mensuel · FCFA', array_map(fn (array $m): array => [$m[0], $m[1]['revDone'], Fin::thousands($m[1]['revDone'])], $months), 'var(--c-accent-600)'),
            ],
            'toolbar' => [$this->viewTools($ins, '/tresorerie')[0], UI::button('Exporter', 'download', $this->generate($ins, 'synthese', 'Excel'), 'primary')],
        ], 'Trésorerie opérationnelle', 'Flux suivis par la plateforme · encaissements, reversements, remboursements et frais', [['Trésorerie']]);
    }

    /**
     * Remboursements et extournes : impact sur le revenu net et coûts supportés.
     */
    public function refunds(Request $request): Response
    {
        $ins = $this->insights($request);
        $p = $ins->period;
        $list = $ins->scoped->flatMap(fn (array $x): array => array_map(fn (array $r): array => ['x' => $x, 'r' => $r], $x['refunds']))
            ->filter(fn (array $o): bool => ($o['r']['date'] ?? $o['x']['d']['op']) >= $p['from'] && ($o['r']['date'] ?? $o['x']['d']['op']) <= $p['to'])->values();
        $done = $list->filter(fn (array $o): bool => $o['r']['status'] === 'Effectué');
        $sum = fn (Collection $c): int => (int) $c->sum(fn (array $o): int => $o['r']['amount']);
        $isTotal = fn (array $o): bool => str_contains(mb_strtolower($o['r']['type']), 'total');
        $toWorker = fn (array $o): bool => $o['r']['beneficiary'] === $o['x']['worker'];
        $comRef = (int) $done->sum(fn (array $o): int => $o['x']['comRef']);
        $lost = (int) $done->sum(fn (array $o): int => $o['x']['feeLost']);
        $cancelled = $ins->current->filter(fn (array $x): bool => $x['payStatus'] === 'Annulé' && ! $x['test']);
        $kinds = [
            ['Remboursement au promoteur', $done->reject($toWorker)->count(), $sum($done->reject($toWorker))],
            ['Remboursement à l’ouvrier', $done->filter($toWorker)->count(), $sum($done->filter($toWorker))],
            ['Remboursement de commission', $done->filter(fn (array $o): bool => $o['x']['comRef'] > 0)->count(), $comRef],
            ['Extourne comptable', $ins->current->where('comExt', '>', 0)->count(), (int) $ins->current->sum('comExt')],
            ['Annulation avant encaissement', $cancelled->count(), (int) $cancelled->sum('promoterTotal')],
        ];
        $motifs = $done->groupBy(fn (array $o): string => $o['r']['motif'])->map(fn (Collection $c, string $k): array => ['motif' => $k, 'n' => $c->count(), 'amount' => $sum($c)])->sortByDesc('amount')->values();
        $shown = $ins->q === '' ? $list : $list->filter(fn (array $o): bool => $ins->search(collect([$o['x']]))->isNotEmpty())->values();
        $page = UI::paginate($shown, (int) $request->query('page', 1), fn (array $o): array => UI::row($o['x']['ref'].$o['r']['date'], [
            UI::text($o['x']['opRef'], true), UI::text($o['x']['promoter']), UI::text($o['x']['site']), UI::text($o['r']['type']), UI::text($o['r']['motif']), UI::money($o['r']['amount'], true), UI::money($o['x']['comRef']),
            UI::text(Fin::date($o['r']['date'])), UI::badge($o['r']['status'], $o['r']['status'] === 'Effectué' ? 'g' : 'a'),
        ], self::paymentLink($o['x']) ?? UI::link($ins->url("/commissions/{$o['x']['ref']}")), [['receipt_long', 'Voir la commission', UI::link($ins->url("/commissions/{$o['x']['ref']}"))]]));

        return $this->render($ins, 'remboursements', [
            ...$this->filterBar($ins, '/remboursements'), 'search' => true,
            'tiles' => [
                UI::tile('Montant total remboursé', Fin::money($sum($done)), 'undo', 'o'), UI::tile('Nombre de remboursements', $done->count(), 'tag', 'b'),
                UI::tile('Remboursements partiels', $done->reject($isTotal)->count(), 'splitscreen', 'b'), UI::tile('Remboursements totaux', $done->filter($isTotal)->count(), 'crop_square', 'b'),
                UI::tile('Commissions remboursées', Fin::money($comRef), 'percent', 'o', ['sub' => 'Diminuent la commission nette']), UI::tile('Frais non récupérables', Fin::money($lost), 'money_off', 'r'),
                UI::tile('Pertes liées aux remboursements', Fin::money($comRef + $lost), 'trending_down', 'r'),
                UI::tile('Taux de remboursement', Fin::percent(Fin::ratio($sum($done), $ins->a['recv'])), 'percent', 'o', ['sub' => 'Remboursé ÷ encaissé de la période']),
                UI::tile('En attente d’approbation', Fin::money($sum($list->filter(fn (array $o): bool => $o['r']['status'] !== 'Effectué' && ! preg_match('/Refus|Rejet/u', $o['r']['status'])))), 'hourglass_top', 'a'),
            ],
            'flow' => [
                'steps' => array_map(fn (array $k, int $i): array => UI::step($k[0], "{$k[1]} · ".Fin::money($k[2]), $k[1] ? ($i > 2 ? 'warn' : 'cur') : 'todo', ['person', 'engineering', 'percent', 'block', 'cancel'][$i]), $kinds, array_keys($kinds)),
                'stacks' => [UI::stack('Ventilation', $p['range'], array_map(fn (array $k): array => ["{$k[0]} ({$k[1]})", $k[2]], $kinds))],
            ],
            'toolbar' => [$this->viewTools($ins, '/remboursements')[0], UI::button('Traiter dans Paiements', 'credit_card', UI::link('/paiements/remboursements')), UI::button('Rapport des remboursements', 'download', $this->generate($ins, 'remboursements', 'Excel'), 'primary')],
            'tables' => [
                UI::table('category', 'Répartition par nature', 'Distinction remboursement, extourne et annulation', ['Nature', 'Nombre', 'Montant'], array_map(fn (array $k): array => UI::row($k[0], [UI::text($k[0], true), UI::text((string) $k[1]), UI::money($k[2])]), $kinds), 700),
                UI::table('label', 'Motifs principaux', $motifs->count().' motif(s)', ['Motif', 'Nombre', 'Montant', 'Part'], $motifs->map(fn (array $m): array => UI::row($m['motif'], [UI::text($m['motif'], true), UI::text((string) $m['n']), UI::money($m['amount']), UI::progress(Fin::ratio($m['amount'], $sum($done)))]))->all(), 700, ['undo', 'Aucun remboursement', 'Aucun remboursement effectué sur la période.']),
                UI::table('undo', 'Remboursements', $page['label'], ['Opération', 'Promoteur', 'Chantier', 'Type', 'Motif', 'Montant', 'Commission remboursée', 'Date', 'Statut'], $page['rows'], 1300, ['undo', 'Aucun remboursement', 'Aucun remboursement sur la période et les filtres actifs.'], $page['pagination']),
            ],
        ], 'Remboursements et extournes', 'Impact des remboursements sur le revenu net et les coûts supportés', [['Remboursements']]);
    }

    /**
     * Frais des opérateurs : coût et performance des moyens de paiement.
     */
    public function fees(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('cout')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les coûts.');
        }
        $a = $ins->a;
        $cfg = config('admin.finance');
        $labels = ['Orange Money' => 'Frais Orange Money', 'MTN Mobile Money' => 'Frais MTN Mobile Money', 'Moov Money' => 'Frais Moov Money', 'Wave' => 'Frais Wave', 'Carte bancaire' => 'Frais carte bancaire', 'Virement bancaire' => 'Frais bancaires'];
        $groups = Fin::group($ins->current->filter(fn (array $x): bool => $x['elig'] && ($x['paid'] || $x['payStatus'] === 'Échoué')), fn ($x) => $x['method'])->sortByDesc(fn (array $g): int => $g['a']['fees'])->values();
        $failure = fn (array $g): ?float => Fin::ratio($g['a']['nFail'], $g['a']['nOk'] + $g['a']['nFail']);
        $bar = $this->filterBar($ins, '/frais-operateurs');

        return $this->render($ins, 'frais-operateurs', [
            ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('info', 'info', 'Frais hors commissions', 'Les frais des opérateurs ne sont jamais intégrés au total des commissions O’CHANTIER. Barèmes lus dans Paiements.')],
            'tiles' => [
                UI::tile('Frais totaux', Fin::money($a['fees']), 'swap_horiz', 'o', ['sub' => 'Encaissement '.Fin::money($a['feeIn']).' · reversement '.Fin::money($a['feeOut'])]),
                UI::tile('Refacturés au promoteur', Fin::money($a['feeIn']), 'receipt', 'b', ['sub' => 'Payeur des frais : '.config('admin.payments.fee_payer')]),
                UI::tile('Supportés par O’CHANTIER', Fin::money($a['feeOch']), 'money_off', 'r', ['sub' => 'Reversements + frais non récupérables']),
                UI::tile('Taux moyen', Fin::percent(Fin::ratio($a['fees'], $a['recv'] + $a['revDone'])), 'percent', 'b', ['sub' => 'Budget '.Fin::money($cfg['objectives']['fees']).' / mois']),
            ],
            'charts' => [
                UI::chart('Frais par prestataire', "FCFA · {$ins->period['range']}", $groups->map(fn (array $g): array => [str_replace(' Mobile Money', '', $g['key']), $g['a']['fees'], Fin::thousands($g['a']['fees'])])->all(), 'var(--c-warning)'),
                UI::chart('Taux d’échec des paiements', '% des tentatives par prestataire', $groups->map(fn (array $g): array => [str_replace(' Mobile Money', '', $g['key']), $failure($g) ?? 0, Fin::percent($failure($g) ?? 0)])->all(), 'var(--c-danger)'),
            ],
            'toolbar' => [$this->viewTools($ins, '/frais-operateurs')[0], UI::button('Rapport des frais', 'download', $this->generate($ins, 'frais', 'Excel'), 'primary')],
            'tables' => [UI::table('swap_horiz', 'Performance des prestataires de paiement', $groups->count().' moyen(s) de paiement', ['Poste de frais', 'Prestataire', 'Volume traité', 'Opérations', 'Frais d’encaissement', 'Frais de reversement', 'Montant des frais', 'Taux moyen effectif', 'Taux d’échec', 'Délai moyen de confirmation', 'Coût moyen par transaction'], $groups->map(function (array $g) use ($ins, $labels, $failure, $cfg): array {
                $delays = $g['entries']->filter(fn (array $x): bool => $x['paid'] && $x['initiatedAt'] && $x['d']['pay'])->map(fn (array $x): int => FinanceLedger::days($x['initiatedAt'], $x['d']['pay']));
                $open = UI::link($ins->url('/commissions', ['moyen' => $g['key']]));

                return UI::row($g['key'], [
                    UI::text($labels[$g['key']] ?? 'Autres frais de paiement', true), UI::text($g['first']['provider']), UI::money($g['a']['recv']), UI::text((string) ($g['a']['nOk'] + $g['a']['nFail'])), UI::money($g['a']['feeIn']), UI::money($g['a']['feeOut']),
                    UI::money($g['a']['fees'], true), UI::text(Fin::percent(Fin::ratio($g['a']['fees'], $g['a']['recv'] + $g['a']['revDone']))), UI::badge(Fin::percent($failure($g)), ($failure($g) ?? 0) > $cfg['thresholds']['payment_failures'] ? 'r' : 'g'),
                    UI::text(Fin::daysText($delays->isNotEmpty() ? $delays->avg() : null)), UI::money($g['a']['nOk'] ? $g['a']['fees'] / $g['a']['nOk'] : 0),
                ], $open, [['filter_alt', 'Voir les commissions', $open]]);
            })->all(), 1500, ['swap_horiz', 'Aucune opération', 'Aucun frais sur la période.'])],
        ], 'Frais des opérateurs', 'Coût des moyens de paiement · Orange Money, MTN, Moov, Wave, carte et banque', [['Frais opérateurs']]);
    }

    /**
     * Rapprochement financier : écarts, doublons et contrôle de cohérence avec Paiements.
     */
    public function reconciliations(Request $request): Response
    {
        $ins = $this->insights($request);
        $a = $ins->a;
        $tones = ['Avec écart' => 'r', 'En attente' => 'a', 'Écart traité' => 'g'];
        $list = $ins->search($ins->current->filter(fn (array $x): bool => ! $x['test'] && ($x['dup'] || ($x['ok'] && $x['recSt'] !== 'Rapproché'))));
        $p = UI::paginate($list, (int) $request->query('page', 1), function (array $x) use ($tones): array {
            $status = $x['dup'] ? ($x['dupOk'] ? 'Doublon confirmé' : 'Doublon à confirmer') : $x['recSt'];
            $gap = $x['received'] - $x['promoterTotal'];
            $acts = $x['paymentRef'] ? [['credit_card', 'Voir le paiement', self::paymentLink($x)]] : [];
            if ($x['dup'] && ! $x['dupOk']) {
                $acts[] = ['task_alt', 'Valider l’exclusion', Fin::can('close') ? UI::reason('Valider l’exclusion du doublon', "{$x['opRef']} · {$x['dupWhy']}. L’opération reste exclue des KPI.", 'content_copy', 'Valider l’exclusion', "/finance-reporting/rapprochements/{$x['ref']}/doublon", 'Motif', 'warning') : UI::deny('validation de doublon')];
            } elseif (! $x['dup'] && $x['recSt'] !== 'Écart traité') {
                $acts[] = ['task_alt', 'Marquer comme traité', Fin::can('close') ? UI::reason('Marquer l’écart comme traité', "{$x['opRef']} · écart de ".Fin::money($gap).'. La donnée source dans Paiements n’est pas modifiée.', 'task_alt', 'Valider', "/finance-reporting/rapprochements/{$x['ref']}/ecart", 'Justification (écart expliqué, correction demandée…)') : UI::deny('résolution d’écart')];
            }

            return UI::row($x['ref'], [
                UI::text($x['opRef'], true), UI::text($x['extRef'] ?: '—'), UI::text($x['promoter']), UI::money($x['promoterTotal']), UI::money($x['received']), UI::text($gap ? Fin::money($gap) : '0 FCFA', true), UI::money($x['com']),
                UI::money($x['dup'] ? 0 : $x['comEnc']), UI::money($x['feeExp']), UI::money($x['feeIn']), $x['workerSlug'] ? UI::money($x['net']) : UI::text('—', false, true), UI::money($x['workerSlug'] && $x['payoutStatus'] === 'Réussi' ? $x['net'] : 0),
                UI::text($x['dup'] ? $x['dupWhy'] : match ($x['recSt']) {
                    'Avec écart' => 'Montant reçu différent',
                    'Écart traité' => 'Écart justifié',
                    default => 'Relevé prestataire à vérifier',
                }),
                UI::badge($status, $x['dup'] ? ($x['dupOk'] ? 'g' : 'r') : ($tones[$x['recSt']] ?? 'n')),
            ], self::paymentLink($x), $acts);
        });

        return $this->render($ins, 'rapprochements', [
            ...$this->filterBar($ins, '/rapprochements'), 'search' => true,
            'tiles' => [
                UI::tile('Opérations totales', $a['n'] + $a['exclDup'], 'receipt', 'b', ['sub' => "{$a['exclDup']} doublon(s) exclu(s)"]), UI::tile('Rapprochées', $a['recOk'], 'task_alt', 'g'),
                UI::tile('Non rapprochées', $a['recElig'] - $a['recOk'], 'pending', 'a'), UI::tile('Avec écart', $a['recGap'], 'difference', 'r'), UI::tile('En attente', $a['recWait'], 'hourglass_top', 'a'),
                UI::tile('Taux de rapprochement', Fin::percent(Fin::ratio($a['recOk'], $a['recElig'])), 'percent', 'b', ['bar' => Fin::ratio($a['recOk'], $a['recElig']) ?? 0, 'sub' => 'Rapprochées ÷ opérations encaissées']),
                UI::tile('Montant des écarts', Fin::money($a['gapAmt']), 'functions', $a['gapAmt'] ? 'r' : 'g'),
            ],
            'toolbar' => [$this->viewTools($ins, '/rapprochements')[0], UI::button('Rapport de rapprochement', 'download', $this->generate($ins, 'rapprochement', 'Excel'), 'primary')],
            'tables' => [
                UI::table('difference', 'Écarts et opérations à rapprocher', $p['label'], ['Opération', 'Référence externe', 'Promoteur', 'Montant attendu', 'Montant reçu', 'Écart', 'Commission attendue', 'Commission comptabilisée', 'Frais attendus', 'Frais appliqués', 'Reversement prévu', 'Reversement effectué', 'Anomalie', 'Statut'], $p['rows'], 2200, ['task_alt', 'Aucun écart', 'Toutes les opérations encaissées de la période sont rapprochées.'], $p['pagination']),
                $this->consistencyTable($ins, $ins->consistency()),
            ],
        ], 'Rapprochement financier', 'Correspondance entre montants attendus, reçus, commissions, frais et reversements', [['Rapprochements']]);
    }

    /**
     * Anomalies et risques financiers : alertes par niveau et concentration du revenu.
     */
    public function risks(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('anom')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les anomalies.');
        }
        $th = config('admin.finance.thresholds');
        $total = $ins->a['comNet'];
        $share = fn (callable $by, int $n): ?float => $total ? Fin::group($ins->current, $by)->take($n)->sum(fn (array $g): int => $g['a']['comNet']) / $total * 100 : null;
        $concentration = [['Premier promoteur', $share(fn ($x) => $x['promoter'], 1), $th['top1']], ['Cinq premiers promoteurs', $share(fn ($x) => $x['promoter'], 5), $th['top5']], ['Premier chantier', $share(fn ($x) => $x['site'], 1), $th['top1']], ['Cinq premiers chantiers', $share(fn ($x) => $x['site'], 5), $th['top5']], ['Premier métier', $share(fn ($x) => $x['trade'], 1), null], ['Cinq premières communes', $share(fn ($x) => $x['commune'], 5), null]];
        $risks = $ins->risks();
        $tones = ['Critique' => 'r', 'Élevé' => 'o', 'Modéré' => 'a', 'Faible' => 'b', 'Information' => 'n'];

        return $this->render($ins, 'anomalies', [
            ...$this->filterBar($ins, '/anomalies'),
            'tiles' => array_map(fn (array $c): array => UI::tile('Part · '.mb_strtolower($c[0]), Fin::percent($c[1]), 'pie_chart', $c[2] && $c[1] > $c[2] ? 'r' : 'b', ['bar' => $c[1] ?? 0, 'barColor' => $c[2] && $c[1] > $c[2] ? 'var(--c-danger)' : 'var(--c-primary-500)', 'sub' => $c[2] ? "Seuil {$c[2]} % (Paramètres)" : 'Suivi sans seuil']), $concentration),
            'toolbar' => [UI::button('Rapport des anomalies', 'download', $this->generate($ins, 'anomalies', 'PDF'), 'primary')],
            'tables' => [UI::table('report', 'Indicateurs de risque', count($risks).' alerte(s) · '.count(array_filter($risks, fn (array $r): bool => $r['level'] === 'Critique')).' critique(s)', ['Niveau', 'Alerte', 'Détail'], array_map(function (array $r, int $i) use ($tones): array {
                $go = $r['rates'] ? UI::rates() : UI::link($r['href']);

                return UI::row("risk-{$i}", [UI::badge($r['level'], $tones[$r['level']]), UI::text($r['title'], true), UI::text($r['text'])], $go, [['arrow_forward', $r['cta'], $go]]);
            }, $risks, array_keys($risks)), 1100, ['verified', 'Aucun risque détecté', 'Aucune alerte avec la période et les filtres actifs.'])],
        ], 'Anomalies et risques financiers', 'Alertes par niveau : Information, Faible, Modéré, Élevé, Critique · concentration du revenu', [['Anomalies']]);
    }

    /**
     * Objectifs et budgets : réalisé, objectif proratisé, écart, atteinte et projection.
     */
    public function objectives(Request $request): Response
    {
        $ins = $this->insights($request);
        $a = $ins->a;
        $p = $ins->period;
        $o = config('admin.finance.objectives');
        $factor = $p['full'] / 30.4;
        $elapsed = min(1, $p['days'] / $p['full']);
        $bar = $this->filterBar($ins, '/objectifs');
        $rows = [
            ['Volume d’affaires', 'm', $a['vol'], $o['volume'] * $factor, false, null], ['Commissions nettes', 'm', $a['comNet'], $o['commissions'] * $factor, false, null],
            ['Marge contributive', 'm', $a['marge'], $o['margin'] * $factor, false, 'marge'], ['Budget frais opérateurs', 'm', $a['fees'], $o['fees'] * $factor, true, 'cout'],
            ['Budget remboursements', 'm', $a['refAmt'], $o['refunds'] * $factor, true, null], ['Objectif de recouvrement', 'p', Fin::ratio($a['dueOk'], $a['dueTot']), $o['recovery'], false, null],
            ['Délai de reversement', 'j', $a['avgRev'], $o['payout_delay'], true, null], ['Taux de captation', 'p', Fin::ratio($a['comNet'], $a['vol']), $o['capture'], false, null],
        ];

        return $this->render($ins, 'objectifs', [
            ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('info', 'flag', 'Objectifs et budgets', "Valeurs issues de Paramètres (proratisées sur {$p['full']} jours). Les projections de fin de période sont des estimations linéaires, pas des montants garantis.", 'Paramètres', Fin::can('param') ? UI::link('/parametres') : UI::deny('accès aux Paramètres'))],
            'tables' => [UI::table('flag', "Réalisé et objectifs · {$ins->period['range']}", round($elapsed * 100).' % de la période écoulée', ['Indicateur', 'Réalisé', 'Objectif', 'Écart', 'Taux d’atteinte', 'Projection fin de période (estimation)', 'Statut'], array_map(function (array $r) use ($elapsed): array {
                [$label, $unit, $value, $target, $budget, $right] = $r;
                $ok = ! $right || Fin::can($right);
                $projection = $value === null ? null : ($unit === 'm' ? $value / max(0.01, $elapsed) : $value);
                $reached = $value === null || ! $target ? null : $value / $target * 100;
                [$status, $tone] = match (true) {
                    $value === null => ['Non calculable', 'n'],
                    $budget => $projection <= $target ? ['Dans le budget', 'g'] : ['Dépassement projeté', 'r'],
                    $reached >= 100 => ['Atteint', 'g'],
                    $projection >= $target => ['En bonne voie', 'b'],
                    default => ['En retard', 'o'],
                };
                $gap = $value === null ? '—' : ($unit === 'm' ? Fin::money($value - $target) : ($value - $target >= 0 ? '+' : '− ').str_replace('.', ',', (string) abs(round($value - $target, 1))).($unit === 'p' ? ' pt' : ' j'));

                return UI::row($label, [
                    UI::text($label, true), UI::text($ok ? Fin::formatValue($unit, $value) : Fin::HIDDEN), UI::text($ok ? Fin::formatValue($unit, $target) : Fin::HIDDEN), UI::text($ok ? $gap : '—'),
                    UI::progress(min(100, $reached ?? 0)), UI::text($ok ? Fin::formatValue($unit, $projection).($unit === 'm' ? ' (estimation)' : '') : Fin::HIDDEN), UI::badge($ok ? $status : 'Accès restreint', $ok ? $tone : 'n'),
                ]);
            }, $rows), 1300)],
        ], 'Objectifs et budgets', 'Réalisé, objectif, écart, taux d’atteinte et projection', [['Objectifs']]);
    }

    /**
     * Prévisions : projections à partir des échéances connues (estimations, aucun montant garanti).
     */
    public function forecasts(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('cout')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les prévisions.');
        }
        $st = $ins->stock;
        $t = FinanceLedger::today()->format('Y-m-d');
        $unpaid = $ins->scoped->filter(fn (array $x): bool => $x['elig'] && ! $x['paid']);
        $payouts = $st['revPend'] + (int) $unpaid->filter(fn (array $x): bool => (bool) $x['workerSlug'])->sum('net');
        $fees = (int) round($st['creance'] * 0.01);
        $flux = $st['creance'] - $payouts - $st['refPend'] - $fees;
        $in7 = FinanceLedger::addDays($t, 7);
        $in30 = FinanceLedger::addDays($t, 30);
        $horizons = [
            ['Échu', fn (array $x): bool => $x['due'] < $t], ['7 prochains jours', fn (array $x): bool => $x['due'] >= $t && $x['due'] <= $in7],
            ['8 à 30 jours', fn (array $x): bool => $x['due'] > $in7 && $x['due'] <= $in30], ['Au-delà de 30 jours', fn (array $x): bool => $x['due'] > $in30],
        ];
        $bar = $this->filterBar($ins, '/previsions');

        return $this->render($ins, 'previsions', [
            ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('warning', 'online_prediction', 'Estimations', 'Prévisions calculées uniquement à partir des opérations existantes, des échéances connues, des taux configurés et de l’historique disponible. Aucun montant n’est garanti.')],
            'tiles' => [
                UI::tile('Commissions attendues', Fin::money($st['comPend'] + $st['estim']), 'online_prediction', 'p', ['sub' => 'Estimation · dont '.Fin::money($st['estim']).' sur calculs non validés']),
                UI::tile('Commissions validées non encaissées', Fin::money($st['comPend']), 'schedule', 'a'), UI::tile('Paiements promoteurs attendus', Fin::money($st['creance']), 'south_west', 'g'),
                UI::tile('Reversements à venir', Fin::money($payouts), 'north_east', 'o', ['sub' => 'Encaissés non reversés + à reverser après encaissement']),
                UI::tile('Remboursements prévisibles', Fin::money($st['refPend']), 'undo', 'o', ['sub' => 'Demandes en cours d’approbation']),
                UI::tile('Flux net projeté', Fin::money($flux), 'water_drop', $flux >= 0 ? 'g' : 'r', ['sub' => 'Estimation · frais estimés '.Fin::money($fees)]),
            ],
            'tables' => [UI::table('event_upcoming', 'Échéancier prévisionnel', 'Échéances connues au '.FinanceLedger::today()->format('d/m/Y'), ['Horizon', 'Opérations', 'Paiements promoteurs attendus', 'Commissions attendues', 'Reversements induits', 'Nature'], array_map(function (array $h) use ($unpaid): array {
                $list = $unpaid->filter($h[1]);

                return UI::row($h[0], [UI::text($h[0], true), UI::text((string) $list->count()), UI::money($list->sum('promoterTotal')), UI::money($list->sum('com')), UI::money($list->filter(fn (array $x): bool => (bool) $x['workerSlug'])->sum('net')), UI::badge('Estimation', 'p')]);
            }, $horizons), 1000)],
        ], 'Prévisions', 'Projections identifiées comme estimations · aucun montant garanti', [['Prévisions']]);
    }

    /**
     * Clôture des périodes : contrôles avant clôture, instantanés versionnés et réouverture sur autorisation.
     */
    public function closings(Request $request): Response
    {
        $ins = $this->insights($request);
        $months = FinanceLedger::months();
        $selected = in_array($ins->query['mois'] ?? '', $months, true) ? $ins->query['mois'] : $months[count($months) - 2];
        $closings = FinanceClosing::query()->get()->keyBy('month');
        $closing = $closings->get($selected) ?? new FinanceClosing(['month' => $selected, 'status' => 'Ouverte', 'version' => 0, 'history' => []]);
        $live = fn (string $m): array => FinanceLedger::aggregate($ins->all->filter(fn (array $x): bool => str_starts_with($x['d']['op'], $m)));
        $am = $live($selected);
        $label = FinanceLedger::monthLabel($selected, true);
        $lastDay = CarbonImmutable::parse("{$selected}-01")->endOfMonth()->format('Y-m-d');
        $notValidated = $ins->all->filter(fn (array $x): bool => str_starts_with($x['d']['op'], $selected) && ! $x['valid'] && ! $x['test'] && $x['payStatus'] !== 'Annulé')->count();
        $checks = self::closingChecks($selected, $am, $notValidated);
        $blocking = array_values(array_filter($checks, fn (array $c): bool => $c[3] && ! $c[1]));
        $reserves = array_values(array_filter($checks, fn (array $c): bool => ! $c[3] && ! $c[1]));
        $url = "/finance-reporting/clotures/{$selected}";
        $close = match (true) {
            ! Fin::can('close') => UI::deny('clôture'),
            (bool) $blocking => UI::error('Clôture impossible : '.implode(', ', array_map(fn (array $c): string => mb_strtolower($c[0]), $blocking)).'.'),
            default => UI::confirm("Clôturer {$label} ?", 'Un instantané financier v'.($closing->version + 1).' sera enregistré et utilisé par les rapports historiques.'.($reserves ? ' Réserves : '.implode(' · ', array_column($reserves, 2)).'.' : ''), 'lock', 'Clôturer la période', "{$url}/cloture"),
        };
        $step = fn (string $label, string $action, string $icon): array => UI::button($label, $icon, Fin::can('close') ? UI::post("{$url}/{$action}") : UI::deny($label), 'primary');
        $toolbar = match ($closing->status) {
            'Ouverte' => [$step('Préparer la clôture', 'preparation', 'playlist_add_check')],
            'En préparation' => [$step('Soumettre au contrôle', 'controle', 'send')],
            'À contrôler' => [UI::button('Clôturer la période', 'lock', $close, 'primary')],
            'Clôturée' => [UI::button('Rouvrir la période', 'lock_open', Fin::can('reopen') ? UI::reason("Rouvrir {$label} ?", "L’instantané v{$closing->version} est conservé. Un nouvel instantané sera produit à la prochaine clôture.", 'lock_open', 'Rouvrir avec autorisation', "{$url}/reouverture", 'Motif de réouverture', 'warning') : UI::deny('réouverture (autorisation Direction financière requise)'))],
            'Rouverte avec autorisation' => [UI::button('Clôturer à nouveau', 'lock', $close, 'primary')],
            default => [],
        };
        $snap = $closing->snapshot;
        if ($snap) {
            $toolbar[] = UI::button('Exporter l’instantané', 'download', Fin::can('export') ? UI::download("{$url}/instantane") : UI::deny('export'));
        }
        $alerts = [];
        if (in_array($closing->status, ['Clôturée', 'Rouverte avec autorisation'], true)) {
            $gap = $am['comNet'] - ($snap['comNet'] ?? 0);
            $alerts[] = UI::alert($gap ? 'warning' : 'success', $gap ? 'warning' : 'lock', $closing->isClosed() ? 'Période clôturée' : 'Période rouverte', "Les rapports historiques utilisent l’instantané v{$closing->version}.".($gap ? ' Données postérieures à la clôture : écart de '.Fin::money($gap).' non intégré à l’instantané.' : ' Aucune donnée postérieure à la clôture.').($closing->reason ? " Motif de réouverture : {$closing->reason}" : ''));
        }
        $current = array_search($closing->status, FinanceClosing::STATUSES, true);
        $statusTones = ['Ouverte' => 'b', 'En préparation' => 'a', 'À contrôler' => 'o', 'Clôturée' => 'g', 'Rouverte avec autorisation' => 'r'];
        $hide = fn (int|float|null $v): string => Fin::can('marge') ? Fin::money($v) : Fin::HIDDEN;

        return $this->render($ins, 'clotures', [
            'alerts' => $alerts, 'toolbar' => $toolbar,
            'flow' => ['steps' => array_map(fn (string $s, int $i): array => UI::step($s, $i === $current ? 'Statut actuel · '.FinanceLedger::monthLabel($selected) : '', $i === $current ? ($i === 4 ? 'warn' : 'cur') : ($i < $current && $i < 4 ? 'done' : 'todo'), ['lock_open', 'playlist_add_check', 'fact_check', 'lock', 'history'][$i]), FinanceClosing::STATUSES, array_keys(FinanceClosing::STATUSES)), 'stacks' => []],
            'panels' => [
                UI::cards('checklist', "Contrôles avant clôture · {$label}", array_map(fn (array $c): array => UI::card($c[1] ? 'check_circle' : ($c[3] ? 'error' : 'warning'), $c[3] ? 'Contrôle bloquant' : 'Contrôle avec réserve', $c[0], $c[2], $c[1] ? 'Conforme' : ($c[3] ? 'À traiter' : 'Réserve'), $c[1] ? 'g' : ($c[3] ? 'r' : 'a'), match ($c[4]) {
                    '' => null,
                    'paiements' => UI::link('/paiements'),
                    default => UI::link($ins->url($c[4], ['periode' => 'perso', 'du' => "{$selected}-01", 'au' => $lastDay])),
                }), $checks)),
                UI::keyValues('photo_camera', $snap ? "Instantané v{$snap['v']} · ".Fin::date($snap['at']) : 'Aucun instantané', $snap ? [
                    ['Clôturé par', $snap['by']], ['Volume d’affaires', Fin::money($snap['vol'])], ['Commissions promoteurs', Fin::money($snap['comP'])], ['Commissions ouvriers', Fin::money($snap['comO'])], ['Commissions nettes', Fin::money($snap['comNet'])],
                    ['Frais opérateurs', Fin::money($snap['fees'])], ['Remboursements', Fin::money($snap['ref'])], ['Reversements', Fin::money($snap['rev'])], ['Créances', Fin::money($snap['cre'])], ['Dettes envers les ouvriers', Fin::money($snap['dette'])],
                    ['Marge contributive', $hide($snap['marge'])], ['Anomalies', $snap['anom']], ['Version des règles', $snap['ruleV']],
                ] : [['Statut', $closing->status], ['Information', 'L’instantané est créé à la clôture de la période.']]),
                UI::feed('history', 'Instantanés précédents', $closing->history ? array_map(fn (array $h): array => ['icon' => 'inventory_2', 'title' => "Instantané v{$h['v']}", 'text' => '· commissions nettes '.Fin::money($h['comNet']).' · volume '.Fin::money($h['vol']), 'when' => 'Clôturé le '.Fin::date($h['at'])." par {$h['by']}"], $closing->history) : [['icon' => 'info', 'title' => 'Aucun', 'text' => 'instantané antérieur conservé pour cette période.', 'when' => '']]),
            ],
            'tables' => [UI::table('lock_clock', 'Périodes mensuelles', count($months).' mois · cliquez pour sélectionner', ['Période', 'Statut', 'Version', 'Clôturée le', 'Par', 'Volume d’affaires', 'Commissions nettes', 'Marge contributive', 'Anomalies', 'Écart vs instantané'], array_map(function (string $m) use ($ins, $closings, $live, $selected, $statusTones, $hide): array {
                $k = $closings->get($m);
                $status = $k->status ?? 'Ouverte';
                $sn = $k?->snapshot;
                $l = $live($m);
                $gap = $sn ? $l['comNet'] - $sn['comNet'] : null;
                $src = $sn && $status === 'Clôturée' ? ['vol' => $sn['vol'], 'comNet' => $sn['comNet'], 'marge' => $sn['marge']] : $l;
                $go = UI::link($ins->url('/clotures', ['mois' => $m]));

                return UI::row($m, [
                    UI::stacked(FinanceLedger::monthLabel($m, true), $m === $selected ? 'Sélectionnée' : ($sn && $status === 'Clôturée' ? 'Valeurs de l’instantané' : 'Valeurs en direct')), UI::badge($status, $statusTones[$status]),
                    $k?->version ? UI::text("v{$k->version}") : UI::text('—', false, true), UI::text($sn ? Fin::date($sn['at']) : '—'), UI::text($sn['by'] ?? '—'), UI::money($src['vol']), UI::money($src['comNet'], true), UI::text($hide($src['marge'])),
                    UI::text((string) ($sn ? $sn['anom'] : $l['dupOpen'] + $l['recGap'])), $gap === null ? UI::text('—') : ($gap ? UI::money($gap) : UI::text('Aucun', false, true)),
                ], $go, [['checklist', 'Contrôles et instantané', $go]]);
            }, array_reverse($months)), 1400)],
        ], 'Clôture des périodes', 'Ouverte → En préparation → À contrôler → Clôturée · réouverture sur autorisation', [['Clôtures']]);
    }

    /**
     * Contrôles avant clôture : libellé, conforme, détail, bloquant, page de traitement.
     *
     * @param  array<string, mixed>  $a
     * @return array<int, array{0: string, 1: bool, 2: string, 3: bool, 4: string}>
     */
    public static function closingChecks(string $month, array $a, int $notValidated): array
    {
        $lastDay = CarbonImmutable::parse("{$month}-01")->endOfMonth();
        $ended = $lastDay->format('Y-m-d') < FinanceLedger::today()->format('Y-m-d');

        return [
            ['Période terminée', $ended, ($ended ? 'Période échue le ' : 'Se termine le ').$lastDay->format('d/m/Y'), true, ''],
            ['Opérations rapprochées', ! $a['recWait'], $a['recWait'] ? "{$a['recWait']} opération(s) en attente" : "{$a['recElig']} opération(s) rapprochée(s)", true, '/rapprochements'],
            ['Commissions calculées', ! $notValidated, $notValidated ? "{$notValidated} calcul(s) non validé(s) dans Paiements" : 'Toutes les commissions sont calculées', true, 'paiements'],
            ['Remboursements enregistrés', ! $a['refPend'], $a['refPend'] ? Fin::money($a['refPend']).' en attente d’approbation' : 'Aucun remboursement en attente', false, '/remboursements'],
            ['Reversements comptabilisés', ! $a['revPend'], $a['revPend'] ? Fin::money($a['revPend']).' encore à reverser' : 'Tous les reversements sont comptabilisés', false, '/reversements'],
            ['Écarts traités', ! $a['recGap'], $a['recGap'] ? "{$a['recGap']} écart(s) non traité(s)" : 'Aucun écart ouvert', true, '/rapprochements'],
            ['Données synchronisées', true, 'Synchronisées le '.Fin::lastSync(), true, ''],
            ['Anomalies critiques résolues', ! $a['dupOpen'], $a['dupOpen'] ? "{$a['dupOpen']} doublon(s) à confirmer" : 'Aucune anomalie critique', true, '/rapprochements'],
        ];
    }

    /**
     * Rapports financiers : catalogue, compte de résultat contributif mensuel et historique des générations.
     */
    public function reports(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('rap')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter les rapports.');
        }
        $rt = PaymentLedger::config();
        $cfg = config('admin.finance');
        $closings = FinanceClosing::query()->get()->keyBy('month');
        $last = FinanceReport::query()->latest('generated_at')->get();
        $hide = fn (string $right, string $v): string => Fin::can($right) ? $v : Fin::HIDDEN;
        $catalog = collect(FinanceReports::CATALOG)->map(function (array $r, string $id) use ($ins, $last): array {
            $ok = Fin::can($r[1]) && Fin::can('export');
            $l = $last->firstWhere('name', $r[0]);

            return UI::row($id, [
                UI::text($r[0], true), UI::badge($ok ? 'Autorisé' : 'Non autorisé', $ok ? 'g' : 'n'), $l ? UI::text($l->generated_at->format('d/m/Y H:i')." · {$l->format}") : UI::text('—', false, true), UI::text('PDF · Excel · CSV'),
            ], $ok ? UI::export($id) : UI::deny($r[0]), [['picture_as_pdf', 'PDF', $this->generate($ins, $id, 'PDF')], ['table_view', 'Excel', $this->generate($ins, $id, 'Excel')], ['description', 'CSV', $this->generate($ins, $id, 'CSV')]]);
        })->values()->all();
        $byMonth = $ins->scoped->groupBy(fn (array $x): string => substr($x['d']['op'], 0, 7));
        $statement = array_map(function (string $m) use ($ins, $closings, $byMonth, $hide): array {
            $k = $closings->get($m);
            $snap = $k?->isClosed() && $k->snapshot && ! $ins->filters ? $k->snapshot : null;
            $l = FinanceLedger::aggregate($byMonth->get($m, collect()));
            $v = $snap ? ['vol' => $snap['vol'], 'comP' => $snap['comP'], 'comO' => $snap['comO'], 'comNet' => $snap['comNet'], 'fees' => $snap['fees'], 'ref' => $snap['ref'], 'marge' => $snap['marge']]
                : ['vol' => $l['vol'], 'comP' => $l['comNetP'], 'comO' => $l['comNetO'], 'comNet' => $l['comNet'], 'fees' => $l['fees'], 'ref' => $l['refAmt'], 'marge' => $l['marge']];

            return UI::row($m, [
                UI::text(FinanceLedger::monthLabel($m, true), true), UI::badge($snap ? "Instantané v{$snap['v']}" : 'Données en direct', $snap ? 'g' : 'b'), UI::money($v['vol']), UI::money($v['comP']), UI::money($v['comO']), UI::money($v['comNet'], true),
                UI::text($hide('cout', '− '.Fin::money($v['fees']))), UI::text('− '.Fin::money($v['ref'])), UI::badge($hide('marge', Fin::money($v['marge'])), $v['marge'] >= 0 ? 'g' : 'r'), UI::text($k->status ?? 'Ouverte'),
            ]);
        }, array_reverse(FinanceLedger::months()));
        $history = FinanceReport::query()->latest('generated_at')->latest('id')->get();

        return $this->render($ins, 'reports', [
            'alerts' => $ins->filters ? [UI::alert('info', 'filter_alt', 'Filtres actifs', 'Le compte de résultat est recalculé en direct : les instantanés ne couvrent que la vue sans filtre.')] : [],
            'panels' => [UI::keyValues('rule', 'Règles de calcul', [
                ['Commission promoteur (Y)', "{$rt['y']} %".($rt['y'] ? '' : ' (désactivée)').' · Paramètres'], ['Commission ouvrier (Z)', "{$rt['z']} %".($rt['z'] ? '' : ' (désactivée)').' · Paramètres'],
                ['Règle active', "{$rt['rule']['name']} · {$rt['rule']['version']}"], ['Revenu reconnu', 'Commissions nettes : encaissées − remboursées − extournées'],
                ['Marge contributive', "Commissions nettes − frais supportés − coûts commerciaux ({$cfg['sales_cost_rate']} %) − remboursements supportés − coûts directs ({$cfg['direct_cost']} FCFA/op.)"],
                ['Marge nette', 'Non calculée : charges fixes, amortissements, charges financières et impôts non disponibles'], ['Hors revenu', 'Reversements ouvriers, frais opérateurs, taxes collectées pour tiers'],
                ['Périodes clôturées', 'Les rapports historiques utilisent l’instantané de clôture'],
            ], UI::button('Taux de commission', 'settings', UI::rates()))],
            'toolbar' => [UI::button('Construire un rapport', 'tune', UI::export('synthese'), 'primary')],
            'tables' => [
                UI::table('summarize', 'Catalogue des rapports', count(FinanceReports::CATALOG)." rapports · période {$ins->period['range']} · base ".mb_strtolower($ins->basisLabel()), ['Rapport', 'Autorisation', 'Dernière génération', 'Formats'], $catalog, 1100),
                UI::table('account_balance', 'Compte de résultat contributif mensuel', '12 derniers mois · base date d’opération · FCFA', ['Mois', 'Source', 'Volume d’affaires', 'Commissions Y nettes', 'Commissions Z nettes', 'Commissions nettes', 'Frais opérateurs', 'Remboursements', 'Marge contributive', 'Période'], $statement, 1500),
                UI::table('history', 'Rapports générés', $history->count().' rapport(s)', ['Rapport', 'Format', 'Période', 'Base temporelle', 'Lignes', 'Généré le', 'Par'], $history->map(fn (FinanceReport $r): array => UI::row((string) $r->id, [
                    UI::text($r->name, true), UI::badge($r->format, 'b'), UI::text($r->period), UI::text($r->basis), UI::text((string) $r->rows), UI::text($r->generated_at->format('d/m/Y H:i')), UI::text($r->author),
                ]))->all(), 1000, ['history', 'Aucun rapport généré']),
            ],
        ], 'Rapports financiers', 'Génération PDF, Excel et CSV · chaque rapport indique période, base, filtres, sources et statut des données', [['Rapports']]);
    }

    /**
     * Dictionnaire des indicateurs.
     */
    public function definitions(Request $request): Response
    {
        $ins = $this->insights($request);
        $list = $ins->search(collect(Fin::definitions())->values(), fn (array $k): string => "{$k['name']} {$k['cat']} {$k['formula']}");

        return $this->render($ins, 'definitions', [
            'search' => true, 'sheets' => $this->sheets($ins),
            'tiles' => [UI::tile('Indicateurs définis', count(Fin::definitions()), 'menu_book', 'b'), UI::tile('Base temporelle active', $ins->basisLabel(), 'event', 'b'), UI::tile('Fréquence de recalcul', '15 min', 'update', 'g', ['sub' => 'Et à chaque changement de filtre'])],
            'tables' => [UI::table('menu_book', 'Dictionnaire des KPI', $list->count().' indicateur(s) · cliquez pour la fiche complète', ['Indicateur', 'Catégorie', 'Valeur', 'Précédent', 'Comparaison', 'Formule', 'Source', 'Responsable'], $list->map(function (array $k) use ($ins): array {
                $v = $ins->kpi($k['id']);

                return UI::row($k['id'], [UI::text($k['name'], true), UI::badge($k['cat'], 'b'), UI::text($v['text'], true), UI::text($v['prevText']), UI::text($v['cmp']), UI::text($k['formula']), UI::text('Paiements'), UI::text($k['owner'])],
                    UI::kpi($k['id']), [['info', 'Définition complète', UI::kpi($k['id'])], ['filter_alt', 'Détail filtré', UI::link($ins->url($k['drill']))]]);
            })->all(), 1700, ['search_off', 'Aucun indicateur', 'Aucun indicateur ne correspond à la recherche.'])],
        ], 'Définitions des indicateurs', 'Nom, définition, formule, numérateur, dénominateur, statuts, source, base et responsable', [['Définitions']]);
    }

    /**
     * Journal d’audit : consultations sensibles, exports, rapports, clôtures, réouvertures, résolutions d’écart.
     */
    public function audit(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Fin::can('audit')) {
            return $this->missing($ins, 'Accès non autorisé', 'Votre rôle ne permet pas de consulter l’audit.');
        }
        $events = FinanceAudit::query()->latest('occurred_at')->latest('id')->get();

        return $this->render($ins, 'audit', [
            'tiles' => [
                UI::tile('Événements tracés', $events->count(), 'history', 'b'), UI::tile('Exports et rapports', $events->where('category', 'document')->count(), 'download', 'b'),
                UI::tile('Clôtures et réouvertures', $events->where('category', 'statut')->count(), 'lock', 'o'), UI::tile('Consultations sensibles', $events->where('category', 'controle')->count(), 'visibility', 'n'),
            ],
            'toolbar' => [UI::button('Exporter l’audit', 'download', Fin::can('export') ? UI::download('/finance-reporting/audit/export') : UI::deny('export'), 'primary')],
            'timeline' => $events->map(fn (FinanceAudit $e): array => self::activity($e))->all(),
        ], 'Journal d’audit Finance & Reporting', 'Consultations sensibles, exports, rapports, clôtures, réouvertures, résolutions d’écart', [['Audit']]);
    }
}
