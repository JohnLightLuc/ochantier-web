<?php

namespace App\Support;

use App\Models\FinanceAudit;
use App\Models\Promoter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Contexte d’analyse de Finance & Reporting pour une requête : période et période de comparaison, base temporelle,
 * filtres globaux et recherche, entrées retenues et agrégats (période, comparaison, encours à date), dictionnaire
 * des indicateurs, alertes de risque et contrôle de cohérence avec le module Paiements.
 *
 * Les droits dépendent du rôle financier configuré : les montants non autorisés sont masqués.
 */
class FinanceInsights
{
    public const PERIODS = ['mois' => 'Ce mois', 'trim' => 'Ce trimestre', 'annee' => 'Cette année', '12m' => '12 derniers mois', 'perso' => 'Personnalisée'];

    /**
     * Filtres globaux : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const FILTERS = [
        'promoteur' => 'Promoteur', 'chantier' => 'Chantier', 'ouvrier' => 'Ouvrier', 'metier' => 'Métier', 'commune' => 'Commune', 'ville' => 'Ville', 'type' => 'Type d’opération',
        'prestation' => 'Type de prestation', 'source' => 'Source de commission', 'moyen' => 'Moyen de paiement', 'prestataire' => 'Prestataire de paiement', 'paiement' => 'Statut du paiement',
        'reversement' => 'Statut du reversement', 'commission' => 'Statut de la commission', 'rapprochement' => 'Statut du rapprochement', 'responsable' => 'Responsable', 'zone' => 'Zone', 'agence' => 'Agence',
    ];

    public const ESSENTIAL_FILTERS = ['promoteur', 'chantier', 'metier', 'commune', 'commission'];

    /**
     * Paramètres d’URL conservés d’une page à l’autre du module.
     *
     * @var array<int, string>
     */
    public const CONTEXT = ['periode', 'du', 'au', 'base', 'tous'];

    public const HIDDEN = '•••• (accès restreint)';

    public const OWNERS = ['Revenus' => 'Direction financière', 'Activité' => 'Contrôle de gestion', 'Rentabilité' => 'Direction financière', 'Qualité' => 'Responsable paiements', 'Coûts' => 'Contrôle de gestion', 'Obligations' => 'Responsable trésorerie', 'Trésorerie' => 'Responsable trésorerie'];

    public const RISK_LEVELS = ['Critique', 'Élevé', 'Modéré', 'Faible', 'Information'];

    /** @var array<string, mixed> */
    public array $period;

    public string $basis;

    /** @var array<string, string> */
    public array $filters;

    public string $q;

    /** @var Collection<int, array<string, mixed>> */
    public Collection $all;

    /** @var Collection<int, array<string, mixed>> Entrées filtrées (toutes dates). */
    public Collection $scoped;

    /** @var Collection<int, array<string, mixed>> Entrées de la période. */
    public Collection $current;

    /** @var Collection<int, array<string, mixed>> Entrées de la période de comparaison. */
    public Collection $previous;

    /** @var array<string, int|float|null> */
    public array $a;

    /** @var array<string, int|float|null> */
    public array $prev;

    /** @var array<string, int|float|null> Encours à date (indépendant de la période). */
    public array $stock;

    /** @var Collection<string, Promoter>|null */
    private ?Collection $promoters = null;

    /**
     * @param  array<string, mixed>  $query
     */
    public function __construct(public FinanceLedger $ledger, public array $query = [])
    {
        $this->basis = array_key_exists($query['base'] ?? '', FinanceLedger::BASES) ? $query['base'] : 'op';
        $this->period = self::period($query['periode'] ?? null, $query['du'] ?? null, $query['au'] ?? null);
        $this->filters = array_filter(array_intersect_key(array_map(fn ($v): string => (string) $v, $query), self::FILTERS), fn (string $v, string $k): bool => $v !== '' && ($k !== 'ouvrier' || self::can('ouv')), ARRAY_FILTER_USE_BOTH);
        $this->q = mb_strtolower(trim((string) ($query['q'] ?? '')));
        $this->all = $ledger->entries();
        $this->scoped = $this->all->filter(fn (array $x): bool => collect($this->filters)->every(fn (string $v, string $k): bool => self::filterValue($k, $x) === $v))->values();
        $this->current = $this->inPeriod($this->scoped, $this->period['from'], $this->period['to']);
        $this->previous = $this->period['comparable'] ? $this->inPeriod($this->scoped, $this->period['previousFrom'], $this->period['previousTo']) : collect();
        $this->a = FinanceLedger::aggregate($this->current);
        $this->prev = FinanceLedger::aggregate($this->previous);
        $this->stock = FinanceLedger::aggregate($this->scoped);
    }

    public static function role(): string
    {
        return (string) config('admin.finance.role');
    }

    /**
     * Droit du rôle financier courant (vue, com, cre, rev, pro, ouv, marge, cout, rap, export, anom, audit, close, reopen, param).
     */
    public static function can(string $right): bool
    {
        $rights = config('admin.finance.roles')[self::role()] ?? config('admin.finance.roles')['Lecture seule'];

        return $rights === 'all' || in_array($right, $rights, true);
    }

    public static function denied(string $what = ''): string
    {
        return 'Action non autorisée pour le rôle '.self::role().($what ? " · {$what}" : '').'.';
    }

    /**
     * Période choisie, période de comparaison de même durée et durée complète (pour proratiser les objectifs).
     *
     * @return array<string, mixed>
     */
    public static function period(?string $key, ?string $from = null, ?string $to = null): array
    {
        $t = FinanceLedger::today();
        $key = array_key_exists((string) $key, self::PERIODS) ? $key : 'mois';
        $date = fn (?string $v): ?CarbonImmutable => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v) : null;
        $first = CarbonImmutable::parse(FinanceLedger::months()[0].'-01');
        if ($key === 'perso') {
            $d1 = $date($from) ?? $t->startOfMonth();
            $d2 = $date($to) ?? $t;
            if ($d1->greaterThan($d2)) {
                [$d1, $d2] = [$d2, $d1];
            }
            $length = (int) $d1->diffInDays($d2);
            $p2 = $d1->subDay();
            $p1 = $p2->subDays($length);
            $end = $d2;
        } else {
            [$d1, $months] = match ($key) {
                'trim' => [$t->firstOfQuarter(), 3],
                'annee' => [$t->startOfYear(), 12],
                '12m' => [$t->startOfMonth()->subMonthsNoOverflow(11), 12],
                default => [$t->startOfMonth(), 1],
            };
            $d2 = $t;
            $p1 = $d1->subMonthsNoOverflow($months);
            $p2 = $t->subMonthsNoOverflow($months);
            $end = $key === '12m' ? $t : $d1->addMonthsNoOverflow($months)->subDay();
        }
        $comparable = $p1->greaterThanOrEqualTo($first);

        return [
            'key' => $key, 'label' => self::PERIODS[$key] === 'Personnalisée' ? 'Période personnalisée' : self::PERIODS[$key],
            'from' => $d1->format('Y-m-d'), 'to' => $d2->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
            'previousFrom' => $p1->format('Y-m-d'), 'previousTo' => $p2->format('Y-m-d'), 'comparable' => $comparable,
            'compare' => $comparable ? $p1->format('d/m/Y').' → '.$p2->format('d/m/Y') : '',
            'days' => (int) $d1->diffInDays($d2) + 1, 'full' => (int) $d1->diffInDays($end) + 1,
            'range' => $d1->format('d/m/Y').' → '.$d2->format('d/m/Y'),
        ];
    }

    public function basisLabel(): string
    {
        return FinanceLedger::BASES[$this->basis];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    public function inPeriod(Collection $entries, string $from, string $to): Collection
    {
        return $entries->filter(fn (array $x): bool => ($d = $x['d'][$this->basis]) !== null && $d >= $from && $d <= $to)->values();
    }

    /**
     * @param  array<string, mixed>  $x
     */
    public static function filterValue(string $key, array $x): ?string
    {
        return match ($key) {
            'promoteur' => $x['promoter'], 'chantier' => $x['site'], 'ouvrier' => $x['worker'], 'metier' => $x['trade'], 'commune' => $x['commune'], 'ville' => $x['city'],
            'type' => $x['type'], 'prestation' => $x['prestation'], 'source' => $x['srcCom'], 'moyen' => $x['method'], 'prestataire' => $x['provider'], 'paiement' => $x['payStatus'],
            'reversement' => $x['revSt'], 'commission' => $x['comStatus'], 'rapprochement' => $x['recSt'], 'responsable' => $x['responsible'], 'zone' => $x['zone'], 'agence' => $x['agency'],
            default => null,
        };
    }

    /**
     * Libellé des filtres actifs (« Promoteur : SICOGI · Commune : Cocody »).
     */
    public function filterSummary(): string
    {
        return collect($this->filters)->map(fn (string $v, string $k): string => self::FILTERS[$k]." : {$v}")->implode(' · ');
    }

    /**
     * Listes déroulantes des filtres (essentiels ou tous), avec les valeurs présentes dans les données.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filterSelects(bool $all): array
    {
        return collect(self::FILTERS)
            ->filter(fn (string $l, string $k): bool => ($all || in_array($k, self::ESSENTIAL_FILTERS, true)) && ($k !== 'ouvrier' || self::can('ouv')))
            ->map(fn (string $label, string $key): array => [
                'key' => $key, 'label' => "{$label} : tous", 'value' => $this->filters[$key] ?? '',
                'options' => $this->all->map(fn (array $x): ?string => self::filterValue($key, $x))->filter(fn (?string $v): bool => $v && $v !== '—')->unique()->sort(fn ($a, $b) => strcoll($a, $b))->values()->map(fn (string $v): array => ['value' => $v, 'label' => $v])->all(),
            ])->values()->all();
    }

    /**
     * Recherche (références, chantier, promoteur, ouvrier, commune, métier).
     *
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function search(Collection $items, ?callable $text = null): Collection
    {
        if ($this->q === '') {
            return $items->values();
        }
        $text ??= fn (array $x): string => implode(' ', [$x['ref'], $x['opRef'], $x['extRef'], $x['site'], $x['promoter'], self::can('ouv') ? $x['worker'] : '', $x['commune'], $x['trade']]);

        return $items->filter(fn ($x): bool => str_contains(mb_strtolower($text($x)), $this->q))->values();
    }

    /**
     * Lien interne au module qui conserve la période, la base et (sauf demande contraire) les filtres.
     *
     * @param  array<string, mixed>  $extra
     */
    public function url(string $path, array $extra = [], bool $keepFilters = true): string
    {
        $query = array_filter([...array_intersect_key($this->query, array_flip(self::CONTEXT)), ...($keepFilters ? $this->filters : []), ...$extra], fn ($v): bool => $v !== null && $v !== '');

        return '/finance-reporting'.$path.($query ? '?'.http_build_query($query) : '');
    }

    /**
     * Nom de l’ouvrier, masqué sans le droit « ouv ».
     *
     * @param  array<string, mixed>  $x
     */
    public static function workerName(array $x): string
    {
        return self::can('ouv') ? $x['worker'] : 'Ouvrier ••••';
    }

    public static function money(int|float|null $v): string
    {
        if ($v === null || ! is_finite((float) $v)) {
            return 'Non calculable';
        }

        return ($v < 0 ? '− ' : '').number_format(abs((float) $v), 0, ',', ' ').' FCFA';
    }

    public static function percent(int|float|null $v): string
    {
        return $v === null || ! is_finite((float) $v) ? 'Non calculable' : str_replace('.', ',', (string) round((float) $v, 1)).' %';
    }

    public static function daysText(int|float|null $v): string
    {
        return $v === null || ! is_finite((float) $v) ? 'Non calculable' : str_replace('.', ',', (string) round((float) $v, 1)).' j';
    }

    public static function ratio(int|float|null $a, int|float|null $b): ?float
    {
        return $a === null || ! $b ? null : $a / $b * 100;
    }

    public static function thousands(int|float $v): string
    {
        return number_format($v / 1000, 0, ',', ' ').' k';
    }

    public static function millions(int|float $v): string
    {
        return str_replace('.', ',', (string) round($v / 1e6, 1)).' M';
    }

    public static function formatValue(string $unit, int|float|null $v): string
    {
        return match ($unit) {
            'm' => self::money($v),
            'p' => self::percent($v),
            'j' => self::daysText($v),
            default => $v === null ? 'Non calculable' : number_format((float) $v, 0, ',', ' '),
        };
    }

    public static function date(?string $d): string
    {
        return $d ? CarbonImmutable::parse($d)->format('d/m/Y') : '—';
    }

    /**
     * Regroupe les entrées (clé vide ou « — » ignorée), triées par commissions nettes puis volume.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array{key: string, entries: Collection<int, array<string, mixed>>, a: array<string, mixed>, first: array<string, mixed>}>
     */
    public static function group(Collection $entries, callable $by): Collection
    {
        return $entries->groupBy(fn (array $x): string => (string) $by($x))
            ->reject(fn (Collection $g, string $k): bool => $k === '' || $k === '—')
            ->map(fn (Collection $g, string $k): array => ['key' => $k, 'entries' => $g->values(), 'a' => FinanceLedger::aggregate($g), 'first' => $g->first()])
            ->sort(fn (array $a, array $b): int => [$b['a']['comNet'], $b['a']['vol']] <=> [$a['a']['comNet'], $a['a']['vol']])
            ->values();
    }

    /**
     * Série mensuelle (12 mois) selon la base temporelle : [libellé court, valeur, mois].
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<int, array{0: string, 1: mixed, 2: string}>
     */
    public function byMonth(Collection $entries, callable $value): array
    {
        $by = $entries->groupBy(fn (array $x): string => substr((string) $x['d'][$this->basis], 0, 7));

        return array_map(fn (string $m): array => [explode(' ', FinanceLedger::monthLabel($m))[0], $value(FinanceLedger::aggregate($by->get($m, collect()))), $m], FinanceLedger::months());
    }

    /**
     * Dictionnaire des indicateurs : définition, formule, numérateur, dénominateur, statuts inclus et exclus,
     * page de détail et droit requis. « stock » : encours à date, indépendant de la période.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $r = fn (?float $a, ?float $b): ?float => self::ratio($a, $b);
        $list = [
            ['com_enc', 'Commissions encaissées', 'Revenus', 'm', fn ($a) => $a['comEnc'], 'Commissions O’CHANTIER (Y + Z) liées à des paiements confirmés et effectivement encaissés.', 'Σ commissions des opérations au paiement confirmé', 'Commissions des paiements encaissés', '—', 'Paiement réussi, partiellement remboursé', 'Estimées, en attente, échouées, annulées, tests, doublons', '/commissions', 'com'],
            ['vol', 'Volume d’affaires traité', 'Activité', 'm', fn ($a) => $a['vol'], 'Montant brut des prestations éligibles, net des annulations et des remboursements de prestation.', 'Σ montants bruts éligibles − prestations annulées − remboursements de prestation', 'Montants bruts des prestations', '—', 'Calcul validé', 'Commissions, frais opérateurs, taxes, reversements, doublons, tests', '/commissions', 'vue'],
            ['marge', 'Marge contributive O’CHANTIER', 'Rentabilité', 'm', fn ($a) => $a['marge'], 'Contribution des commissions nettes après coûts variables directement rattachables. Charges fixes, amortissements, intérêts et impôts non disponibles : aucune marge nette n’est calculée.', 'Commissions nettes − frais opérateurs supportés − coûts commerciaux variables − remboursements supportés − autres coûts directs', 'Commissions nettes et coûts variables', '—', 'Opérations encaissées', 'Charges fixes et comptables (non intégrées)', '/tresorerie', 'marge'],
            ['com_pend', 'Commissions en attente', 'Revenus', 'm', fn ($a) => $a['comPend'], 'Commissions validées dont le paiement promoteur n’est pas encore encaissé.', 'Σ commissions des opérations validées non encaissées', 'Commissions validées non encaissées', '—', 'Calcul validé, paiement en attente ou échoué', 'Estimées, rejetées, annulées, tests', '/creances', 'com'],
            ['com_p', 'Commission promoteur (Y)', 'Revenus', 'm', fn ($a) => $a['comEncP'], 'Part promoteur des commissions encaissées (frais de service ajoutés au montant payé).', 'Σ part Y des commissions encaissées', 'Commissions Y encaissées', '—', 'Paiement confirmé', 'Commissions ouvrier, frais', '/commissions', 'com'],
            ['com_o', 'Commission ouvrier (Z)', 'Revenus', 'm', fn ($a) => $a['comEncO'], 'Part ouvrier des commissions encaissées (prélevée sur la rémunération).', 'Σ part Z des commissions encaissées', 'Commissions Z encaissées', '—', 'Paiement confirmé', 'Commissions promoteur, frais', '/commissions', 'com'],
            ['com_brut', 'Commission totale brute', 'Revenus', 'm', fn ($a) => $a['comGross'], 'Commissions générées par toutes les opérations éligibles, encaissées ou non.', 'Commission promoteur + commission ouvrier', 'Commissions générées', '—', 'Calcul validé', 'Tests, doublons, annulées', '/commissions', 'com'],
            ['com_net', 'Commission nette', 'Revenus', 'm', fn ($a) => $a['comNet'], 'Commissions réellement conservées par O’CHANTIER.', 'Commissions encaissées − remboursées − extournées − corrections débitrices', 'Commissions encaissées nettes', '—', 'Paiement confirmé', 'Remboursements, extournes', '/commissions', 'com'],
            ['taux_brut', 'Taux effectif brut', 'Revenus', 'p', fn ($a) => $r($a['comGross'], $a['brut']), 'Part des commissions générées dans le volume éligible.', 'Commissions brutes ÷ volume d’affaires éligible × 100', 'Commissions brutes', 'Volume éligible brut', 'Calcul validé', 'Tests, doublons', '/commissions', 'com'],
            ['taux_enc', 'Taux effectif encaissé', 'Revenus', 'p', fn ($a) => $r($a['comEnc'], $a['brutEnc']), 'Part des commissions encaissées dans le volume encaissé.', 'Commissions encaissées ÷ volume encaissé éligible × 100', 'Commissions encaissées', 'Volume encaissé éligible', 'Paiement confirmé', 'En attente, échouées', '/commissions', 'com'],
            ['taux_net', 'Taux effectif net', 'Revenus', 'p', fn ($a) => $r($a['comNet'], $a['volEnc']), 'Part des commissions nettes dans le volume net encaissé.', 'Commissions nettes ÷ volume net éligible × 100', 'Commissions nettes', 'Volume net encaissé', 'Paiement confirmé', 'Remboursements déduits', '/commissions', 'com'],
            ['captation', 'Taux de captation O’CHANTIER', 'Revenus', 'p', fn ($a) => $r($a['comNet'], $a['vol']), 'Part du volume d’affaires réellement conservée par O’CHANTIER.', 'Commissions nettes ÷ volume d’affaires net traité × 100', 'Commissions nettes', 'Volume d’affaires net', 'Opérations éligibles', 'Tests, doublons, annulées', '/commissions', 'com'],
            ['ops', 'Opérations réussies', 'Activité', 'n', fn ($a) => $a['nOk'], 'Nombre d’opérations dont le paiement promoteur est confirmé.', 'Nombre d’opérations encaissées', 'Opérations encaissées', '—', 'Paiement confirmé', 'Tests, échecs, annulations, doublons', '/commissions', 'vue'],
            ['panier', 'Panier moyen', 'Activité', 'm', fn ($a) => $a['nOk'] ? $a['volEnc'] / $a['nOk'] : null, 'Volume moyen traité par opération réussie.', 'Volume d’affaires traité ÷ opérations réussies', 'Volume des opérations réussies', 'Opérations réussies', 'Paiement confirmé', 'Tests, échecs, annulations, doublons, opérations sans impact', '/commissions', 'vue'],
            ['com_moy', 'Commission moyenne par opération', 'Activité', 'm', fn ($a) => $a['nComNet'] ? $a['comNet'] / $a['nComNet'] : null, 'Commission nette moyenne par opération ayant généré une commission nette.', 'Commissions nettes ÷ opérations avec commission nette', 'Commissions nettes', 'Opérations avec commission nette > 0', 'Paiement confirmé', 'Tests, échecs, annulations, doublons', '/commissions', 'com'],
            ['refunds', 'Remboursements', 'Qualité', 'm', fn ($a) => $a['refAmt'], 'Montants remboursés (effectués) sur la période.', 'Σ remboursements au statut Effectué', 'Remboursements effectués', '—', 'Effectué', 'Demandés, refusés', '/remboursements', 'vue'],
            ['taux_ref', 'Taux de remboursement', 'Qualité', 'p', fn ($a) => $r($a['refAmt'], $a['recv']), 'Part des encaissements remboursée.', 'Montants remboursés ÷ volume encaissé correspondant × 100', 'Remboursements effectués', 'Encaissements confirmés', 'Effectué', 'Demandés', '/remboursements', 'vue'],
            ['fees', 'Frais opérateurs', 'Coûts', 'm', fn ($a) => $a['fees'], 'Frais facturés par les opérateurs (encaissement refacturé au promoteur + reversement supporté). Jamais intégrés aux commissions.', 'Frais d’encaissement + frais de reversement', 'Frais appliqués', '—', 'Opérations confirmées', 'Commissions', '/frais-operateurs', 'cout'],
            ['taux_fees', 'Taux moyen des frais opérateurs', 'Coûts', 'p', fn ($a) => $r($a['fees'], $a['recv'] + $a['revDone']), 'Coût moyen des opérateurs rapporté aux flux traités.', 'Frais opérateurs ÷ (encaissements + reversements) × 100', 'Frais opérateurs', 'Flux traités', 'Opérations confirmées', '—', '/frais-operateurs', 'cout'],
            ['du_ouv', 'Montant dû aux ouvriers', 'Obligations', 'm', fn ($a) => $a['owe'], 'Rémunérations nettes dues aux ouvriers sur les paiements encaissés. Ne constitue pas un revenu.', 'Σ nets ouvriers des opérations encaissées', 'Nets ouvriers', '—', 'Paiement confirmé', 'Commissions', '/reversements', 'rev'],
            ['rev_done', 'Montant reversé aux ouvriers', 'Obligations', 'm', fn ($a) => $a['revDone'], 'Reversements effectués avec succès.', 'Σ reversements réussis', 'Reversements réussis', '—', 'Reversé', 'Programmés, échoués', '/reversements', 'rev'],
            ['rev_pend', 'Reversements en attente', 'Obligations', 'm', fn ($a) => $a['revPend'], 'Montants encaissés restant à reverser aux ouvriers (encours à date).', 'Montant dû − montant reversé', 'Nets non reversés', '—', 'Disponible, programmé, en cours, échoué, suspendu', 'Reversés', '/reversements', 'rev', true],
            ['creances', 'Créances promoteurs', 'Obligations', 'm', fn ($a) => $a['creance'], 'Montants validés restant à encaisser auprès des promoteurs (encours à date).', 'Σ montants promoteur validés non encaissés', 'Montants non encaissés', '—', 'En attente, échoué', 'Annulés, tests', '/creances', 'cre', true],
            ['cre_ech', 'Créances échues', 'Obligations', 'm', fn ($a) => $a['creEch'], 'Créances dont l’échéance (J+3) est dépassée.', 'Σ créances dont l’échéance < aujourd’hui', 'Créances échues', '—', 'Non encaissées', '—', '/creances', 'cre', true],
            ['recouv', 'Taux de recouvrement', 'Obligations', 'p', fn ($a) => $r($a['dueOk'], $a['dueTot']), 'Part des montants arrivés à échéance effectivement encaissée.', 'Montants encaissés ÷ montants arrivés à échéance × 100', 'Échus encaissés', 'Montants échus', 'Échéance atteinte', 'Non échus', '/creances', 'cre'],
            ['taux_rap', 'Taux de rapprochement', 'Qualité', 'p', fn ($a) => $r($a['recOk'], $a['recElig']), 'Part des opérations encaissées rapprochées avec le relevé du prestataire.', 'Opérations rapprochées ÷ opérations éligibles × 100', 'Rapprochées ou écart traité', 'Opérations encaissées', 'Paiement confirmé', 'Non encaissées', '/rapprochements', 'vue'],
            ['fail_pay', 'Taux d’échec des paiements', 'Qualité', 'p', fn ($a) => $r($a['nFail'], $a['nOk'] + $a['nFail']), 'Part des paiements promoteur en échec.', 'Paiements échoués ÷ (réussis + échoués) × 100', 'Paiements échoués', 'Paiements tentés', 'Réussi, échoué', 'En attente', '/frais-operateurs', 'vue'],
            ['fail_rev', 'Taux d’échec des reversements', 'Qualité', 'p', fn ($a) => $r($a['revFailN'], $a['revN']), 'Part des reversements ouvriers en échec.', 'Reversements échoués ÷ reversements exigibles × 100', 'Reversements échoués', 'Reversements exigibles', 'Paiement confirmé', '—', '/reversements', 'rev'],
            ['delai_enc', 'Délai moyen d’encaissement', 'Qualité', 'j', fn ($a) => $a['avgEnc'], 'Nombre moyen de jours entre l’échéance et l’encaissement.', 'Moyenne (date d’encaissement − échéance)', 'Jours de retard', 'Opérations encaissées', 'Paiement confirmé', '—', '/creances', 'cre'],
            ['delai_rev', 'Délai moyen de reversement', 'Qualité', 'j', fn ($a) => $a['avgRev'], 'Nombre moyen de jours entre l’encaissement et le reversement.', 'Moyenne (date de reversement − date d’encaissement)', 'Jours', 'Reversements réussis', 'Reversé', '—', '/reversements', 'rev'],
            ['taux_marge', 'Taux de marge contributive', 'Rentabilité', 'p', fn ($a) => $r($a['marge'], $a['comNet']), 'Part des commissions nettes restant après coûts variables.', 'Marge contributive ÷ commissions nettes × 100', 'Marge contributive', 'Commissions nettes', 'Opérations encaissées', '—', '/tresorerie', 'marge'],
            ['flux', 'Flux net opérationnel suivi', 'Trésorerie', 'm', fn ($a) => $a['flux'], 'Flux opérationnels suivis par la plateforme. Aucune connexion bancaire : ce n’est pas un solde bancaire.', 'Encaissements confirmés − reversements − remboursements payés − frais opérateurs payés − autres décaissements', 'Encaissements', 'Décaissements', 'Confirmés', 'En attente', '/tresorerie', 'cout'],
            ['couv', 'Couverture des reversements', 'Trésorerie', 'p', fn ($a) => $r($a['fonds'], $a['revPend']), 'Fonds affectables aux ouvriers rapportés aux montants exigibles. Indicateur de pilotage, pas une preuve de solvabilité réglementaire.', 'Fonds affectables aux ouvriers ÷ montants exigibles × 100', 'Fonds encaissés non reversés', 'Montants exigibles', 'Paiement confirmé', 'Reversés', '/tresorerie', 'rev', true],
        ];

        return collect($list)->mapWithKeys(fn (array $k): array => [$k[0] => [
            'id' => $k[0], 'name' => $k[1], 'cat' => $k[2], 'unit' => $k[3], 'value' => $k[4], 'def' => $k[5], 'formula' => $k[6], 'num' => $k[7], 'den' => $k[8], 'inc' => $k[9], 'exc' => $k[10],
            'drill' => $k[11], 'perm' => $k[12], 'stock' => $k[13] ?? false, 'owner' => self::OWNERS[$k[2]],
        ]])->all();
    }

    /**
     * Valeur d’un indicateur, valeur de comparaison et variation (masquées sans le droit requis).
     *
     * @return array<string, mixed>
     */
    public function kpi(string $id): array
    {
        $k = self::definitions()[$id];
        $p = $this->period;
        $cur = $k['value']($k['stock'] ? $this->stock : $this->a);
        $prev = $k['stock'] ? null : $k['value']($this->prev);
        $delta = '';
        if ($k['stock']) {
            $cmp = 'Encours au '.FinanceLedger::today()->format('d/m/Y');
        } elseif (! $p['comparable']) {
            $cmp = 'Comparaison indisponible';
        } elseif ($cur === null) {
            $cmp = 'Non calculable';
        } elseif (in_array($k['unit'], ['p', 'j'], true)) {
            if ($prev === null) {
                $cmp = 'Comparaison indisponible';
            } else {
                $d = round($cur - $prev, 1);
                $delta = ($d >= 0 ? '+' : '− ').str_replace('.', ',', (string) abs($d)).($k['unit'] === 'p' ? ' pt' : ' j');
                $cmp = "{$delta} vs {$p['compare']}";
            }
        } elseif (! $prev) {
            $cmp = $cur ? 'Nouvelle activité' : 'Aucune activité sur les deux périodes';
        } else {
            $d = round(($cur - $prev) / abs($prev) * 100, 1);
            $delta = ($d >= 0 ? '+' : '− ').str_replace('.', ',', (string) abs($d)).' %';
            $cmp = "{$delta} vs {$p['compare']}";
        }
        $ok = self::can($k['perm']);

        return [
            ...array_diff_key($k, ['value' => true]),
            'cur' => $cur, 'ok' => $ok, 'cmp' => $cmp, 'delta' => $delta,
            'text' => $ok ? self::formatValue($k['unit'], $cur) : self::HIDDEN,
            'prevText' => $ok ? ($prev === null ? '—' : self::formatValue($k['unit'], $prev)) : self::HIDDEN,
            'abs' => $ok && $prev !== null && $cur !== null && $k['unit'] === 'm' ? self::money($cur - $prev) : '—',
        ];
    }

    /**
     * Fiche complète d’un indicateur (panneau latéral).
     *
     * @return array<string, mixed>
     */
    public function kpiSheet(string $id, string $sync): array
    {
        $k = $this->kpi($id);
        $today = FinanceLedger::today()->format('d/m/Y');

        return [
            'id' => $id, 'kicker' => "Indicateur · {$k['cat']}", 'title' => $k['name'],
            'badge' => ['label' => $k['delta'] ?: ($k['stock'] ? 'Encours' : 'Sans comparaison'), 'tone' => $k['delta'] ? (str_starts_with($k['delta'], '+') ? 'g' : 'o') : 'n'],
            'meta' => [
                ['Valeur actuelle', $k['text']], ['Période', $k['stock'] ? "Encours au {$today}" : $this->period['range']], ['Valeur précédente', $k['prevText']],
                ['Période de comparaison', $k['stock'] ? '—' : ($this->period['comparable'] ? $this->period['compare'] : 'Indisponible (historique insuffisant)')],
                ['Variation absolue', $k['abs']], ['Variation', $k['delta'] ?: $k['cmp']], ['Définition', $k['def']], ['Formule', $k['formula']], ['Numérateur', $k['num']], ['Dénominateur', $k['den']],
                ['Statuts inclus', $k['inc']], ['Statuts exclus', $k['exc']], ['Source', 'Module Paiements (opérations, commissions, remboursements, reversements) + archives clôturées'],
                ['Base temporelle', $k['stock'] ? 'Situation à date' : $this->basisLabel()], ['Dernière mise à jour', $sync],
                ['Fréquence de recalcul', 'À chaque synchronisation (15 min) et à chaque changement de filtre'], ['Responsable de la donnée', $k['owner']],
            ],
            'drill' => $this->url($k['drill']),
        ];
    }

    /**
     * Promoteur avec un litige ouvert (données de démonstration de la fiche promoteur).
     */
    public function hasOpenDispute(string $promoter): bool
    {
        $this->promoters ??= Promoter::query()->get()->keyBy('name');
        $disputes = $this->promoters->get($promoter)?->demo_relations['disputes'] ?? [];

        return collect($disputes)->contains(fn (array $d): bool => ! preg_match('/Clôtur|Résolu/u', $d['status'] ?? ''));
    }

    public function hasDispute(string $promoter): bool
    {
        $this->promoters ??= Promoter::query()->get()->keyBy('name');

        return ! empty($this->promoters->get($promoter)?->demo_relations['disputes']);
    }

    /**
     * Dernière synchronisation avec le module Paiements.
     */
    public static function lastSync(): string
    {
        $at = FinanceAudit::query()->where('action', 'a relancé la synchronisation')->latest('occurred_at')->value('occurred_at');

        return $at ? CarbonImmutable::parse($at)->format('d/m/Y à H:i') : CarbonImmutable::parse(config('admin.finance.last_sync'))->format('d/m/Y à H:i');
    }

    /**
     * Alertes de risque triées par niveau (Critique → Information).
     *
     * @return array<int, array{level: string, icon: string, title: string, text: string, href: string|null, cta: string, rates?: bool}>
     */
    public function risks(): array
    {
        $out = [];
        $a = $this->a;
        $cfg = config('admin.finance');
        $th = $cfg['thresholds'];
        $t = FinanceLedger::today()->format('Y-m-d');
        $add = function (string $level, string $icon, string $title, string $text, ?string $path = '/anomalies', string $cta = 'Analyser', bool $rates = false) use (&$out): void {
            $href = match (true) {
                $path === null => null,
                str_starts_with($path, '/paiements') => $path,
                default => $this->url($path),
            };
            $out[] = ['level' => $level, 'icon' => $icon, 'title' => $title, 'text' => $text, 'href' => $href, 'cta' => $cta, 'rates' => $rates];
        };
        $sum = fn (Collection $c, callable $f): int => (int) $c->sum($f);
        if (! PaymentLedger::config()['ok']) {
            $add('Critique', 'rule', 'Commissions sans règle valide', 'Aucun taux Y/Z actif dans Paramètres : les nouvelles opérations ne génèrent aucune commission.', null, 'Voir les règles', true);
        }
        $dups = $this->scoped->filter(fn (array $x): bool => $x['dup'] && ! $x['dupOk']);
        if ($dups->isNotEmpty()) {
            $add('Critique', 'content_copy', 'Double opération', $dups->count().' opération(s) en doublon, exclue(s) des KPI · '.self::money($sum($dups, fn ($x) => $x['received'])).' à confirmer.', '/rapprochements', 'Traiter');
        }
        $gaps = $this->scoped->where('recSt', 'Avec écart');
        if ($gaps->isNotEmpty()) {
            $add('Élevé', 'difference', 'Écart de rapprochement', $gaps->count().' opération(s) · '.self::money($sum($gaps, fn ($x) => abs($x['gap']))).' d’écart entre montant attendu et montant reçu.', '/rapprochements', 'Rapprocher');
        }
        if ($this->period['comparable'] && $this->prev['comNet'] && $a['comNet'] < $this->prev['comNet'] * (1 - $th['commission_drop'] / 100)) {
            $add('Élevé', 'trending_down', 'Baisse importante des commissions', 'Commissions nettes en baisse de '.self::percent((1 - $a['comNet'] / $this->prev['comNet']) * 100)." vs {$this->period['compare']} (seuil {$th['commission_drop']} %).", '/commissions');
        }
        $old = $this->scoped->filter(fn (array $x): bool => $x['elig'] && ! $x['paid'] && FinanceLedger::days($x['due'], $t) > 30);
        if ($old->isNotEmpty()) {
            $add('Élevé', 'hourglass_bottom', 'Créances anciennes', $old->count().' créance(s) échue(s) depuis plus de 30 jours · '.self::money($sum($old, fn ($x) => $x['promoterTotal'])).'.', '/creances', 'Balance âgée');
        }
        $limit = $cfg['aging']['payouts'][1];
        $late = $this->scoped->filter(fn (array $x): bool => $x['ok'] && $x['workerSlug'] && $x['payoutStatus'] !== 'Réussi' && $x['d']['enc'] && FinanceLedger::days($x['d']['enc'], $t) > $limit);
        if ($late->isNotEmpty()) {
            $add('Élevé', 'schedule_send', 'Retard de reversement', $late->count()." reversement(s) ouvrier en attente depuis plus de {$limit} jours · ".self::money($sum($late, fn ($x) => $x['net'])).'.', '/reversements');
        }
        $failRev = self::ratio($a['revFailN'], $a['revN']);
        if ($failRev !== null && $failRev > $th['payout_failures']) {
            $add('Élevé', 'error', 'Hausse des échecs de reversement', self::percent($failRev)." des reversements exigibles en échec (seuil {$th['payout_failures']} %).", '/reversements');
        }
        $failPay = self::ratio($a['nFail'], $a['nOk'] + $a['nFail']);
        if ($failPay !== null && $failPay > $th['payment_failures']) {
            $add('Modéré', 'credit_card_off', 'Hausse des échecs de paiement', self::percent($failPay)." des paiements promoteur en échec (seuil {$th['payment_failures']} %).", '/frais-operateurs');
        }
        $refunds = self::ratio($a['refAmt'], $a['recv']);
        if ($refunds !== null && $refunds > $th['refunds']) {
            $add('Modéré', 'undo', 'Hausse des remboursements', 'Taux de remboursement de '.self::percent($refunds)." (seuil {$th['refunds']} %).", '/remboursements');
        }
        $negative = self::group($this->current, fn ($x) => $x['site'])->filter(fn (array $g): bool => $g['a']['nOk'] && $g['a']['marge'] < 0);
        if ($negative->isNotEmpty()) {
            $add('Modéré', 'money_off', 'Marge négative', $negative->count().' chantier(s) avec un résultat contributif négatif : '.$negative->take(3)->pluck('key')->implode(', ').'.', '/chantiers');
        }
        $total = $a['comNet'];
        $byPromoter = self::group($this->current, fn ($x) => $x['promoter']);
        $bySite = self::group($this->current, fn ($x) => $x['site']);
        if ($total && ($g = $byPromoter->first()) && $g['a']['comNet'] / $total * 100 > $th['top1']) {
            $add('Modéré', 'pie_chart', 'Concentration excessive sur un promoteur', "{$g['key']} représente ".self::percent($g['a']['comNet'] / $total * 100)." des commissions nettes (seuil {$th['top1']} %).");
        }
        if ($total && ($g = $bySite->first()) && $g['a']['comNet'] / $total * 100 > $th['top1']) {
            $add('Faible', 'pie_chart', 'Concentration excessive sur un chantier', "{$g['key']} représente ".self::percent($g['a']['comNet'] / $total * 100)." des commissions nettes (seuil {$th['top1']} %).");
        }
        $top5 = $total ? $byPromoter->take(5)->sum(fn (array $g) => $g['a']['comNet']) / $total * 100 : 0;
        if ($top5 > $th['top5']) {
            $add('Faible', 'donut_small', 'Concentration Top 5 promoteurs', 'Les cinq premiers promoteurs représentent '.self::percent($top5)." des commissions nettes (seuil {$th['top5']} %).");
        }
        foreach (self::group($this->current->where('paid', true), fn ($x) => $x['method']) as $g) {
            $rate = self::ratio($g['a']['feeIn'], $g['a']['recv']);
            if ($rate !== null && $rate > $th['fees']) {
                $add('Faible', 'swap_horiz', 'Frais opérateurs anormalement élevés', "{$g['key']} : ".self::percent($rate)." des montants encaissés (seuil {$th['fees']} %).", '/frais-operateurs');
            }
        }
        $incomplete = $this->scoped->filter(fn (array $x): bool => ! $x['valid'] && ! $x['test'] && $x['payStatus'] !== 'Annulé');
        if ($incomplete->isNotEmpty()) {
            $add('Information', 'rule', 'Données incomplètes', $incomplete->count().' opération(s) au calcul non validé exclue(s) des KPI · commissions estimées '.self::money($sum($incomplete, fn ($x) => $x['com'])).'.', '/paiements', 'Ouvrir Paiements');
        }
        if ($a['exclTest']) {
            $add('Information', 'science', 'Opérations de test exclues', "{$a['exclTest']} opération(s) de test n’alimentent pas les KPI réels.", '/commissions', 'Voir le journal');
        }

        return collect($out)->sortBy(fn (array $r): int => array_search($r['level'], self::RISK_LEVELS, true))->values()->all();
    }

    /**
     * Contrôle de cohérence avec le module Paiements (mêmes période, base et filtres) : un écart n’est expliqué
     * que s’il correspond exactement aux doublons exclus.
     *
     * @return array{ok: bool, count: int, rows: array<int, array<string, mixed>>}
     */
    public function consistency(): array
    {
        $list = $this->current->reject(fn (array $x): bool => $x['archived'] || $x['test']);
        $sources = [
            ['Paiements encaissés', 'Opérations sources', fn ($x) => $x['paid'] ? $x['received'] : 0],
            ['Commissions', 'Journal des commissions', fn ($x) => $x['paid'] ? $x['com'] : 0],
            ['Remboursements', 'Remboursements validés', fn ($x) => $x['refAmt']],
            ['Reversements', 'Reversements réussis', fn ($x) => $x['paid'] && $x['workerSlug'] && $x['payoutStatus'] === 'Réussi' ? $x['net'] : 0],
            ['Frais opérateurs', 'Frais enregistrés', fn ($x) => $x['feeIn']],
        ];
        $rows = array_map(function (array $s) use ($list): array {
            $payments = (int) $list->sum($s[2]);
            $finance = (int) $list->where('elig', true)->sum($s[2]);
            $explained = (int) $list->where('dup', true)->sum($s[2]);
            $gap = $payments - $finance;
            [$status, $tone] = ! $gap ? ['Conforme', 'g'] : ($gap === $explained ? ['Écart expliqué · doublon exclu', 'a'] : ['Écart à analyser', 'r']);

            return ['label' => $s[0], 'source' => $s[1], 'payments' => $payments, 'finance' => $finance, 'gap' => $gap, 'status' => $status, 'tone' => $tone];
        }, $sources);

        return ['ok' => collect($rows)->every(fn (array $r): bool => $r['tone'] !== 'r'), 'count' => $list->count(), 'rows' => $rows];
    }
}
