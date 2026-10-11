<?php

namespace App\Support;

use App\Models\FinanceArchive;
use App\Models\FinanceResolution;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Registre de Finance & Reporting : une entrée de commission par opération du module Paiements (montants calculés par
 * PaymentLedger) ou par commission archivée d’une période clôturée, enrichie des données analytiques (zone, agence,
 * type de prestation, source de commission), des exclusions (tests, doublons, calculs non validés) et des montants
 * reconnus (volume net, commission encaissée et nette, frais supportés, statut du reversement et du rapprochement).
 *
 * Les dates d’une entrée sont des chaînes « Y-m-d » : prestation, opération, paiement, encaissement, reversement,
 * rapprochement. L’échéance du paiement promoteur est fixée à J+3 de l’opération.
 */
class FinanceLedger
{
    /**
     * Bases temporelles d’analyse : clé de date d’une entrée → libellé.
     *
     * @var array<string, string>
     */
    public const BASES = ['presta' => 'Date de prestation', 'op' => 'Date d’opération', 'pay' => 'Date de paiement', 'enc' => 'Date d’encaissement', 'rev' => 'Date de reversement', 'rec' => 'Date de rapprochement'];

    public const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public const LONG_MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public PaymentLedger $payments;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $entries = null;

    /** @var array<string, array<string, bool>> */
    private array $resolutions;

    public function __construct(?PaymentLedger $payments = null)
    {
        $this->payments = $payments ?? new PaymentLedger;
        $this->resolutions = FinanceResolution::query()->get()->groupBy('kind')->map(fn (Collection $r): array => $r->pluck('reference')->flip()->map(fn (): bool => true)->all())->all();
    }

    public static function today(): CarbonImmutable
    {
        return Payment::referenceDate();
    }

    /**
     * Mois analysés (« Y-m »), du plus ancien au mois en cours.
     *
     * @return array<int, string>
     */
    public static function months(): array
    {
        $start = self::today()->startOfMonth();

        return array_map(fn (int $i): string => $start->subMonths($i)->format('Y-m'), range((int) config('admin.finance.months') - 1, 0));
    }

    public static function monthLabel(string $month, bool $long = false): string
    {
        $i = (int) substr($month, 5, 2) - 1;

        return ($long ? self::LONG_MONTHS[$i] : self::MONTHS[$i]).' '.substr($month, 0, 4);
    }

    /**
     * Nombre de jours de $from à $to (négatif si $to précède $from).
     */
    public static function days(string $from, string $to): int
    {
        return (int) round(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false));
    }

    public static function addDays(string $date, int $days): string
    {
        return CarbonImmutable::parse($date)->addDays($days)->format('Y-m-d');
    }

    /**
     * Toutes les entrées : archives clôturées puis opérations du module Paiements (doublons détectés).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(): Collection
    {
        return $this->entries ??= $this->load();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $reference): ?array
    {
        return $this->entries()->firstWhere('ref', $reference);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function load(): Collection
    {
        $archives = FinanceArchive::with('site.promoter', 'worker')->orderBy('id')->get()->map(fn (FinanceArchive $a): array => $this->complete($this->fromArchive($a)));
        $seen = [];
        $keys = [];
        $operations = $this->payments->all()->sortBy('id')->map(function (Payment $p) use (&$seen, &$keys): array {
            $x = $this->fromPayment($p);
            $byRef = $p->external_reference ? ($seen[$p->external_reference] ?? null) : null;
            $byKey = $p->calc_status !== 'Annulé' ? ($keys[$p->key()] ?? null) : null;
            if ($p->external_reference && ! $byRef) {
                $seen[$p->external_reference] = $p->reference;
            }
            $keys[$p->key()] ??= $p->reference;
            if ($byRef || $byKey) {
                $x['dup'] = true;
                $x['dupOf'] = $byRef ?? $byKey;
                $x['dupWhy'] = $byRef ? "Référence externe {$p->external_reference} déjà comptabilisée ({$byRef})" : "Même affectation, période et type que {$byKey}";
            }

            return $this->complete($x);
        });

        return $archives->concat($operations)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function fromPayment(Payment $p): array
    {
        $c = $this->payments->calc($p);
        $enc = $c['paid'] && $p->paid_at ? ($p->method === 'Virement bancaire' ? $p->paid_at->addDay() : $p->paid_at)->format('Y-m-d') : null;
        $reconciled = $c['paid'] && str_contains($p->reconciliation, 'Rapproché');

        return [
            ...$this->place($p->site, $p->worker),
            'ref' => 'COM-'.substr($p->reference, 3), 'opRef' => $p->reference, 'paymentRef' => $p->reference, 'extRef' => (string) $p->external_reference, 'assignment' => $p->assignment_reference,
            'archived' => false, 'test' => false, 'dup' => false, 'dupOf' => null, 'dupWhy' => null,
            'type' => $p->type, 'calcStatus' => $p->calc_status, 'valid' => in_array($p->calc_status, ['Validé', 'Corrigé'], true),
            'payStatus' => $p->pay_status, 'payoutStatus' => $p->payout_status, 'comStatus' => $c['comStatus'], 'reconciliation' => $p->reconciliation,
            'method' => $p->method ?? '—', 'provider' => $p->provider ?? '—', 'workerMethod' => (string) $p->worker_method, 'responsible' => $p->responsible,
            'd' => ['presta' => $p->period_end->format('Y-m-d'), 'op' => $p->date->format('Y-m-d'), 'pay' => $p->paid_at?->format('Y-m-d'), 'enc' => $enc, 'rev' => $p->payout_at?->format('Y-m-d'), 'rec' => $reconciled ? self::addDays($enc ?? $p->date->format('Y-m-d'), 1) : null],
            'initiatedAt' => $p->initiated_at?->format('Y-m-d'), 'days' => $p->days, 'rate' => $p->daily_rate,
            'gross' => $c['gross'], 'bonus' => $c['bonus'] + $c['indemnity'], 'deduction' => $c['deduction'], 'y' => $c['y'], 'z' => $c['z'], 'comP' => $c['comP'], 'comO' => $c['comO'], 'com' => $c['com'],
            'paid' => $c['paid'], 'received' => $c['received'], 'promoterTotal' => $c['promoterTotal'], 'feeIn' => $c['paid'] ? $c['fees'] : 0, 'feeExp' => $c['fees'], 'tax' => $c['tax'], 'net' => $c['net'], 'gap' => $c['gap'],
            'comRef' => $c['comRefunded'], 'comExt' => $c['comReversed'], 'refAmt' => $c['refunded'],
            'refunds' => $p->refunds->map(fn (PaymentRefund $r): array => ['type' => $r->type, 'amount' => $r->amount, 'motif' => $r->motif, 'beneficiary' => $r->beneficiary, 'status' => $r->status, 'date' => $r->date->format('Y-m-d')])->values()->all(),
            'ruleVersion' => $p->rule_version, 'attempts' => $p->attempts, 'message' => (string) $p->provider_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromArchive(FinanceArchive $a): array
    {
        $gross = $a->days * $a->daily_rate;
        $comP = (int) round($gross * $a->promoter_rate / 100);
        $comO = (int) round($gross * $a->worker_rate / 100);
        $fee = config('admin.payments.fees')[$a->method] ?? [0, 0];
        $sub = $gross + $comP;
        $fees = (int) round($sub * $fee[0] / 100) + $fee[1];
        $total = $sub + $fees;
        $paid = in_array($a->pay_status, Payment::PAID, true);
        $refunded = (int) collect($a->refunds)->where('status', 'Effectué')->sum('amount');
        $op = $a->service_date->format('Y-m-d');

        return [
            ...$this->place($a->site, $a->worker),
            'ref' => $a->reference, 'opRef' => $a->operation_reference, 'paymentRef' => null, 'extRef' => (string) $a->external_reference, 'assignment' => null,
            'archived' => true, 'test' => $a->is_test, 'dup' => false, 'dupOf' => null, 'dupWhy' => null,
            'type' => $a->type, 'calcStatus' => 'Validé', 'valid' => true,
            'payStatus' => $a->pay_status, 'payoutStatus' => $a->payout_status, 'comStatus' => $a->commission_status, 'reconciliation' => $a->reconciliation,
            'method' => $a->method, 'provider' => $a->provider, 'workerMethod' => (string) $a->worker_method, 'responsible' => $a->responsible,
            'd' => ['presta' => $op, 'op' => $op, 'pay' => $a->paid_at?->format('Y-m-d'), 'enc' => $a->collected_at?->format('Y-m-d'), 'rev' => $a->payout_at?->format('Y-m-d'), 'rec' => $a->reconciled_at?->format('Y-m-d')],
            'initiatedAt' => $a->initiated_at?->format('Y-m-d'), 'days' => $a->days, 'rate' => $a->daily_rate,
            'gross' => $gross, 'bonus' => 0, 'deduction' => 0, 'y' => $a->promoter_rate, 'z' => $a->worker_rate, 'comP' => $comP, 'comO' => $comO, 'com' => $comP + $comO,
            'paid' => $paid, 'received' => $paid ? $total : 0, 'promoterTotal' => $total, 'feeIn' => $paid ? $fees : 0, 'feeExp' => $fees, 'tax' => 0, 'net' => $gross - $comO, 'gap' => 0,
            'comRef' => (int) round(($comP + $comO) * $refunded / max(1, $total)), 'comExt' => 0, 'refAmt' => $refunded,
            'refunds' => $a->refunds, 'ruleVersion' => $a->rule_version, 'attempts' => $a->attempts, 'message' => (string) $a->message,
        ];
    }

    /**
     * Chantier, promoteur, localisation (zone et agence d’après la commune) et ouvrier.
     *
     * @return array<string, mixed>
     */
    private function place(?object $site, ?object $worker): array
    {
        [$zone, $agency] = config('admin.finance.zones')[$site?->commune] ?? ['Abidjan Centre', 'Agence Plateau'];
        $trade = $worker?->trade ?? 'Acompte chantier';

        return [
            'siteId' => $site?->id, 'workerId' => $worker?->id, 'promoterId' => $site?->promoter_id,
            'site' => $site?->name ?? '—', 'siteSlug' => $site?->slug, 'promoter' => $site?->promoter_name ?? '—', 'promoterSlug' => $site?->promoter?->slug,
            'commune' => $site?->commune ?? '—', 'city' => $site?->city ?? 'Abidjan', 'zone' => $zone, 'agency' => $agency,
            'worker' => $worker?->name ?? '—', 'workerSlug' => $worker?->slug, 'workerCode' => $worker?->code, 'trade' => $trade,
            'prestation' => match (true) {
                ! $worker => 'Acompte chantier',
                (bool) preg_match('/Maçon|Ferrail|Coffr|Manœuvre|Terrass|Chef/iu', $trade) => 'Gros œuvre',
                (bool) preg_match('/Électri|Plomb|Climat|Soud/iu', $trade) => 'Réseaux techniques',
                (bool) preg_match('/Peint|Carrel|Menuis|Plâtr|Vitr|Étanch/iu', $trade) => 'Finitions',
                default => 'Autres travaux',
            },
        ];
    }

    /**
     * Montants reconnus : éligibilité, volume net, commissions encaissées et nettes, statuts analytiques.
     *
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>
     */
    private function complete(array $x): array
    {
        $x['srcCom'] = $x['comP'] && $x['comO'] ? 'Partagée' : ($x['comP'] ? 'Promoteur' : ($x['comO'] ? 'Ouvrier' : 'Aucune'));
        $x['dupOk'] = isset($this->resolutions[FinanceResolution::DUPLICATE][$x['ref']]);
        $x['recFixed'] = isset($this->resolutions[FinanceResolution::GAP][$x['ref']]);
        $x['elig'] = ! $x['test'] && ! $x['dup'] && $x['valid'] && $x['payStatus'] !== 'Annulé' && $x['calcStatus'] !== 'Annulé';
        $ok = $x['elig'] && $x['paid'];
        $total = max(1, $x['promoterTotal']);
        $x['ok'] = $ok;
        $x['refPresta'] = (int) round($x['refAmt'] * $x['gross'] / $total);
        $x['feeLost'] = (int) round($x['feeIn'] * $x['refAmt'] / $total);
        $x['vol'] = $x['elig'] ? $x['gross'] - $x['refPresta'] : 0;
        $x['comEnc'] = $ok ? $x['com'] : 0;
        $x['comNet'] = $ok ? max(0, $x['com'] - $x['comRef'] - $x['comExt']) : 0;
        $x['revSt'] = match (true) {
            ! $x['workerSlug'] => 'Non applicable',
            ! $ok => 'Non disponible',
            default => ['Réussi' => 'Reversé', 'Échoué' => 'Échoué', 'Suspendu' => 'Suspendu', 'En cours' => 'En cours', 'Programmé' => 'Programmé'][$x['payoutStatus']] ?? 'Disponible',
        };
        $x['recSt'] = match (true) {
            ! $ok => 'Non éligible',
            $x['recFixed'] => 'Écart traité',
            str_contains($x['reconciliation'], 'Rapproché') => 'Rapproché',
            $x['reconciliation'] === 'Écart détecté' || $x['gap'] !== 0 => 'Avec écart',
            default => 'En attente',
        };
        $rate = config('admin.payments.fees')[$x['workerMethod']] ?? [1, 0];
        $x['feeOut'] = $ok && $x['workerSlug'] && $x['payoutStatus'] === 'Réussi' ? (int) round($x['net'] * $rate[0] / 100) + $rate[1] : 0;
        $x['due'] = self::addDays($x['d']['op'], 3);
        $x['stShow'] = match (true) {
            $x['test'] => 'Test (exclue)',
            $x['dup'] => 'Doublon (exclu)',
            ! $x['valid'] => 'Calcul non validé',
            default => $x['comStatus'],
        };

        return $x;
    }

    /**
     * Agrégats d’un ensemble d’entrées (tests, doublons et non éligibles comptés à part).
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $entries
     * @return array<string, int|float|null>
     */
    public static function aggregate(iterable $entries): array
    {
        $t = self::today()->format('Y-m-d');
        $keys = ['n', 'nOk', 'nFail', 'nComNet', 'vol', 'volEnc', 'brut', 'brutEnc', 'comGross', 'comP', 'comO', 'comEnc', 'comEncP', 'comRef', 'comExt', 'comNet', 'comNetP', 'comPend', 'pendN', 'pendAge', 'pendDue', 'pendLate',
            'feeIn', 'feeOut', 'feeLost', 'refAmt', 'refN', 'refPart', 'refTot', 'refPend', 'recv', 'creance', 'creEch', 'dueTot', 'dueOk', 'owe', 'revDone', 'revPend', 'revFail', 'revFailN', 'revN', 'fonds',
            'recElig', 'recOk', 'recGap', 'recWait', 'gapAmt', 'exclTest', 'exclDup', 'dupAmt', 'dupOpen', 'excl', 'estim'];
        $a = array_fill_keys($keys, 0);
        $enc = [];
        $rev = [];
        foreach ($entries as $x) {
            if ($x['test']) {
                $a['exclTest']++;

                continue;
            }
            if ($x['dup']) {
                $a['exclDup']++;
                $a['dupAmt'] += $x['received'];
                $a['dupOpen'] += $x['dupOk'] ? 0 : 1;

                continue;
            }
            if (! $x['elig']) {
                $a['excl']++;
                $a['estim'] += ! $x['valid'] && $x['payStatus'] !== 'Annulé' ? $x['com'] : 0;

                continue;
            }
            $a['n']++;
            $a['vol'] += $x['vol'];
            $a['brut'] += $x['gross'];
            $a['comGross'] += $x['com'];
            $a['comP'] += $x['comP'];
            $a['comO'] += $x['comO'];
            $share = $x['com'] ? $x['comP'] / $x['com'] : 0;
            $a['nFail'] += $x['payStatus'] === 'Échoué' ? 1 : 0;
            if ($x['due'] <= $t) {
                $a['dueTot'] += $x['promoterTotal'];
                $a['dueOk'] += $x['paid'] ? $x['promoterTotal'] : 0;
            }
            if ($x['paid']) {
                $a['nOk']++;
                $a['volEnc'] += $x['vol'];
                $a['brutEnc'] += $x['gross'];
                $a['recv'] += $x['received'];
                $a['comEnc'] += $x['com'];
                $a['comEncP'] += (int) round($x['com'] * $share);
                $a['comRef'] += $x['comRef'];
                $a['comExt'] += $x['comExt'];
                $a['comNet'] += $x['comNet'];
                $a['comNetP'] += (int) round($x['comNet'] * $share);
                $a['nComNet'] += $x['comNet'] > 0 ? 1 : 0;
                $a['feeIn'] += $x['feeIn'];
                $a['feeOut'] += $x['feeOut'];
                $a['feeLost'] += $x['feeLost'];
                $a['recElig']++;
                if (in_array($x['recSt'], ['Rapproché', 'Écart traité'], true)) {
                    $a['recOk']++;
                } elseif ($x['recSt'] === 'Avec écart') {
                    $a['recGap']++;
                    $a['gapAmt'] += abs($x['gap']);
                } else {
                    $a['recWait']++;
                }
                if ($x['d']['enc']) {
                    $enc[] = max(0, self::days($x['due'], $x['d']['enc']));
                }
            } else {
                $a['comPend'] += $x['com'];
                $a['pendN']++;
                $a['pendAge'] += self::days($x['d']['op'], $t);
                $a['pendDue'] += $x['due'] <= $t ? $x['com'] : 0;
                $a['pendLate'] += self::days($x['due'], $t) > 7 ? $x['com'] : 0;
                $a['creance'] += $x['promoterTotal'];
                $a['creEch'] += $x['due'] < $t ? $x['promoterTotal'] : 0;
            }
            foreach ($x['refunds'] as $r) {
                if ($r['status'] === 'Effectué') {
                    $a['refAmt'] += $r['amount'];
                    $a['refN']++;
                    str_contains(mb_strtolower($r['type']), 'total') ? $a['refTot']++ : $a['refPart']++;
                } elseif (! str_contains($r['status'], 'Refus') && ! str_contains($r['status'], 'Rejet')) {
                    $a['refPend'] += $r['amount'];
                }
            }
            if ($x['workerSlug'] && $x['paid']) {
                $a['owe'] += $x['net'];
                $a['revN']++;
                if ($x['payoutStatus'] === 'Réussi') {
                    $a['revDone'] += $x['net'];
                    if ($x['d']['rev'] && $x['d']['enc']) {
                        $rev[] = max(0, self::days($x['d']['enc'], $x['d']['rev']));
                    }
                } else {
                    $a['revPend'] += $x['net'];
                    $a['fonds'] += max(0, $x['received'] - $x['comNet'] - $x['feeIn'] - $x['refAmt']);
                    if ($x['payoutStatus'] === 'Échoué') {
                        $a['revFail'] += $x['net'];
                        $a['revFailN']++;
                    }
                }
            }
        }
        $a['comEncO'] = $a['comEnc'] - $a['comEncP'];
        $a['comNetO'] = $a['comNet'] - $a['comNetP'];
        $a['comm'] = (int) round($a['comNet'] * config('admin.finance.sales_cost_rate') / 100);
        $a['direct'] = $a['nOk'] * (int) config('admin.finance.direct_cost');
        $a['feeOch'] = $a['feeOut'] + $a['feeLost'];
        $a['marge'] = $a['comNet'] - $a['feeOut'] - $a['comm'] - $a['feeLost'] - $a['direct'];
        $a['avgEnc'] = $enc ? array_sum($enc) / count($enc) : null;
        $a['avgRev'] = $rev ? array_sum($rev) / count($rev) : null;
        $a['pendAvg'] = $a['pendN'] ? $a['pendAge'] / $a['pendN'] : null;
        $a['fees'] = $a['feeIn'] + $a['feeOut'];
        $a['flux'] = $a['recv'] - $a['revDone'] - $a['refAmt'] - $a['fees'];

        return $a;
    }

    /**
     * Instantané financier d’un mois (base date d’opération, sans filtre).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $month, string $by, string $at, int $version): array
    {
        $a = self::aggregate($this->entries()->filter(fn (array $x): bool => str_starts_with($x['d']['op'], $month)));

        return [
            'v' => $version, 'at' => $at, 'by' => $by, 'vol' => $a['vol'], 'comP' => $a['comNetP'], 'comO' => $a['comNetO'], 'comNet' => $a['comNet'], 'fees' => $a['fees'], 'ref' => $a['refAmt'],
            'rev' => $a['revDone'], 'cre' => $a['creance'], 'dette' => $a['revPend'], 'marge' => $a['marge'], 'anom' => $a['dupOpen'] + $a['recGap'], 'n' => $a['n'],
            'ruleV' => $month < '2026-01' ? 'COM-2025-v2' : 'COM-2026-v3',
        ];
    }

    /**
     * Balance âgée des créances (tranches configurées, échéance J+3), au total et par promoteur.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{labels: array<int, string>, totals: array<int, int>, rows: array<int, array{name: string, values: array<int, int>, total: int}>}
     */
    public static function aging(Collection $entries): array
    {
        $t = self::today()->format('Y-m-d');
        $b = config('admin.finance.aging.receivables');
        $labels = ['Non échu', "0 à {$b[0]} j"];
        foreach (array_slice($b, 1) as $i => $v) {
            $labels[] = ($b[$i] + 1)." à {$v} j";
        }
        $labels[] = 'Plus de '.end($b).' j';
        $index = function (array $x) use ($b, $t): int {
            $d = self::days($x['due'], $t);
            if ($d < 0) {
                return 0;
            }
            foreach ($b as $i => $limit) {
                if ($d <= $limit) {
                    return $i + 1;
                }
            }

            return count($b) + 1;
        };
        $totals = array_fill(0, count($labels), 0);
        $by = [];
        foreach ($entries as $x) {
            $i = $index($x);
            $totals[$i] += $x['promoterTotal'];
            $by[$x['promoter']] ??= array_fill(0, count($labels), 0);
            $by[$x['promoter']][$i] += $x['promoterTotal'];
        }
        $rows = collect($by)->map(fn (array $v, string $k): array => ['name' => $k, 'values' => $v, 'total' => array_sum($v)])->sortByDesc('total')->values()->all();

        return ['labels' => $labels, 'totals' => $totals, 'rows' => $rows];
    }
}
