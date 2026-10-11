<?php

namespace App\Support;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Présentation du module Paiements : périodes, filtres, raccourcis montants, lignes de tableau, synthèse et exports.
 */
class PaymentInsights
{
    public const PERIODS = ['jour' => 'Aujourd’hui', 'hier' => 'Hier', 'semaine' => 'Cette semaine', 'mois' => 'Ce mois', 'moisp' => 'Mois précédent', 'annee' => 'Cette année', 'perso' => 'Période personnalisée'];

    /**
     * Filtres : clé d’URL → libellé (les six premiers sont visibles sans « Plus de filtres »).
     *
     * @var array<string, string>
     */
    public const FILTERS = [
        'type' => 'Type d’opération', 'paiement' => 'Statut du paiement', 'reversement' => 'Statut du reversement', 'commission' => 'Statut de la commission', 'promoteur' => 'Promoteur', 'chantier' => 'Chantier',
        'calcul' => 'Statut du calcul', 'ouvrier' => 'Ouvrier', 'metier' => 'Métier', 'moyen' => 'Moyen de paiement', 'prestataire' => 'Prestataire', 'avec_commission' => 'Commission', 'mode' => 'Mode de commission',
        'montant' => 'Montant', 'rapprochement' => 'Rapprochement', 'remboursement' => 'Remboursement', 'anomalie' => 'Anomalie', 'responsable' => 'Responsable', 'source' => 'Source de création',
    ];

    public const BASIC_FILTERS = ['type', 'paiement', 'reversement', 'commission', 'promoteur', 'chantier'];

    public const SORTS = ['date' => 'Date', 'brut' => 'Montant brut', 'commission' => 'Commission O’CHANTIER', 'net' => 'Montant net', 'promoteur' => 'Promoteur', 'chantier' => 'Chantier', 'statut' => 'Statut', 'paiement' => 'Date de paiement', 'reversement' => 'Date de reversement'];

    /**
     * Raccourcis montants : clé → libellé, icône, montant (true) ou nombre d’opérations (false).
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    public const SHORTCUTS = [
        'vol' => ['Volume total des opérations', 'receipt_long', true], 'brut' => ['Montants bruts', 'work', true], 'att' => ['Paiements attendus', 'schedule_send', true],
        'pend' => ['Paiements en cours', 'hourglass_top', true], 'recu' => ['Paiements reçus', 'payments', true], 'ech' => ['Paiements échoués', 'error', true],
        'arev' => ['À reverser aux ouvriers', 'savings', true], 'revc' => ['Reversements en cours', 'sync', true], 'revd' => ['Reversements effectués', 'task_alt', true],
        'comc' => ['Commissions calculées', 'calculate', true], 'come' => ['Commissions encaissées', 'account_balance', true], 'rmb' => ['Remboursements', 'currency_exchange', true],
        'arap' => ['Opérations à rapprocher', 'rule', false], 'anom' => ['Anomalies financières', 'report', false], 'revE' => ['Reversements échoués', 'error', true],
        'aenc' => ['Commissions à encaisser', 'schedule', true], 'comr' => ['Commissions remboursées ou extournées', 'undo', true], 'comn' => ['Commissions nettes', 'account_balance', true],
    ];

    /**
     * Sections du module : chemin → libellé, icône, chemins rattachés.
     *
     * @var array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public const SECTIONS = [
        '/paiements' => ['Vue d’ensemble', 'dashboard', []],
        '/paiements/a-reverser' => ['Reversements', 'send', ['/paiements/reversements', '/paiements/lots']],
        '/paiements/remboursements' => ['Remboursements', 'currency_exchange', []],
        '/paiements/operations' => ['Opérations', 'list', []],
        '/paiements/commissions' => ['Commissions', 'percent', ['/paiements/commissions/operations']],
        '/paiements/anomalies' => ['Contrôles', 'fact_check', ['/paiements/rapprochements']],
        '/paiements/rapports' => ['Rapports', 'bar_chart', []],
    ];

    public function __construct(public PaymentLedger $ledger) {}

    public static function today(): CarbonImmutable
    {
        return Payment::referenceDate();
    }

    /**
     * Période choisie et période précédente de même durée.
     *
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, previousFrom: CarbonImmutable, previousTo: CarbonImmutable, label: string}
     */
    public static function period(?string $key, ?string $from = null, ?string $to = null): array
    {
        $t = self::today();
        $key = array_key_exists((string) $key, self::PERIODS) ? $key : 'mois';
        $date = fn (?string $v): ?CarbonImmutable => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v) : null;
        [$d1, $d2] = match ($key) {
            'jour' => [$t, $t],
            'hier' => [$t->subDay(), $t->subDay()],
            'semaine' => [$t->startOfWeek(), $t],
            'moisp' => [$t->startOfMonth()->subMonth(), $t->startOfMonth()->subDay()],
            'annee' => [$t->startOfYear(), $t],
            'perso' => [$date($from) ?? $t->subDays(30), $date($to) ?? $t],
            default => [$t->startOfMonth(), $t],
        };
        $length = (int) $d1->diffInDays($d2) + 1;

        return ['key' => $key, 'from' => $d1, 'to' => $d2, 'previousFrom' => $d1->subDays($length), 'previousTo' => $d1->subDay(), 'label' => $d1->equalTo($d2) ? $d1->format('d/m/Y') : $d1->format('d/m/Y').' → '.$d2->format('d/m/Y')];
    }

    /**
     * @return array<string, string>
     */
    public static function periodMeta(array $period): array
    {
        return ['key' => $period['key'], 'from' => $period['from']->format('Y-m-d'), 'to' => $period['to']->format('Y-m-d'), 'label' => $period['label']];
    }

    /**
     * @return Collection<int, Payment>
     */
    public function inPeriod(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->ledger->all()->filter(fn (Payment $p): bool => $p->date->betweenIncluded($from, $to))->values();
    }

    /**
     * Valeur d’un raccourci pour une opération (montant ou 0/1).
     */
    public function shortcutValue(string $key, Payment $p): int
    {
        $c = $this->ledger->calc($p);
        $validated = in_array($p->calc_status, ['Validé', 'Corrigé'], true);

        return match ($key) {
            'vol' => $c['promoterTotal'],
            'brut' => $c['gross'],
            'att' => in_array($p->pay_status, ['En attente', 'Non initié'], true) && $validated ? $c['promoterTotal'] : 0,
            'pend' => in_array($p->pay_status, ['Initié', 'En cours', 'Autorisation requise'], true) ? $c['promoterTotal'] : 0,
            'recu' => $c['received'],
            'ech' => $p->pay_status === 'Échoué' ? $c['promoterTotal'] : 0,
            'arev' => $c['paid'] && ! $p->isDeposit() && ! in_array($p->payout_status, ['Réussi', 'Annulé'], true) ? $c['net'] : 0,
            'revc' => in_array($p->payout_status, ['Programmé', 'Initié', 'En cours'], true) ? $c['net'] : 0,
            'revd' => $p->payout_status === 'Réussi' ? $c['net'] : 0,
            'comc' => $c['comStatus'] === 'Annulée' ? 0 : $c['com'],
            'come' => $c['comCollected'],
            'rmb' => $c['refunded'],
            'arap' => $c['paid'] && ! in_array($p->reconciliation, ['Rapproché automatiquement', 'Rapproché manuellement', 'Clôturé'], true) ? 1 : 0,
            'anom' => $this->ledger->anomalies($p) ? 1 : 0,
            'revE' => $p->payout_status === 'Échoué' ? $c['net'] : 0,
            'aenc' => $c['comStatus'] === 'À encaisser' ? $c['com'] : 0,
            'comr' => $c['comRefunded'] + $c['comReversed'],
            'comn' => $c['comNet'],
            default => 0,
        };
    }

    /**
     * @param  Collection<int, Payment>  $items
     */
    public function sum(Collection $items, string $key): int
    {
        return (int) $items->sum(fn (Payment $p): int => $this->shortcutValue($key, $p));
    }

    public function filterValue(string $key, Payment $p): ?string
    {
        $c = $this->ledger->calc($p);

        return match ($key) {
            'type' => $p->type,
            'paiement' => $p->pay_status,
            'reversement' => $p->payout_status,
            'commission' => $c['comStatus'],
            'calcul' => $p->calc_status,
            'promoteur' => $p->site->promoter_name,
            'chantier' => $p->site->name,
            'ouvrier' => $p->worker?->name ?? '—',
            'metier' => $p->worker?->trade ?? '—',
            'moyen' => $p->method ?? '—',
            'prestataire' => $p->provider ?? '—',
            'avec_commission' => $c['com'] ? 'Avec commission' : 'Sans commission',
            'mode' => 'Proportionnelle',
            'montant' => match (true) {
                $c['promoterTotal'] < 50000 => '< 50 000',
                $c['promoterTotal'] < 200000 => '50 000 – 200 000',
                $c['promoterTotal'] < 1000000 => '200 000 – 1 000 000',
                default => '≥ 1 000 000',
            },
            'rapprochement' => $p->reconciliation,
            'remboursement' => $p->refunds->isNotEmpty() ? 'Avec remboursement' : 'Sans remboursement',
            'anomalie' => $this->ledger->anomalies($p) ? 'Avec anomalie' : 'Sans anomalie',
            'responsable' => $p->responsible,
            'source' => $p->source,
            default => null,
        };
    }

    /**
     * Recherche (référence, références externes, promoteur, ouvrier, chantier, affectation, téléphone) et filtres.
     *
     * @param  Collection<int, Payment>  $items
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>|null  $keys
     * @return Collection<int, Payment>
     */
    public function filter(Collection $items, array $filters, ?array $keys = null): Collection
    {
        $q = str_replace(' ', '', Str::lower(trim((string) ($filters['q'] ?? ''))));

        return $items->filter(function (Payment $p) use ($filters, $keys, $q): bool {
            if ($q !== '' && ! str_contains(str_replace(' ', '', Str::lower(implode('|', [$p->reference, $p->external_reference, $p->payout_reference, $p->site->promoter_name, $p->worker?->name, $p->worker?->code, $p->site->name, $p->assignment_reference, $p->worker?->phone]))), $q)) {
                return false;
            }
            foreach ($keys ?? array_keys(self::FILTERS) as $key) {
                if (filled($filters[$key] ?? null) && $this->filterValue($key, $p) !== $filters[$key]) {
                    return false;
                }
            }
            $shortcut = $filters['raccourci'] ?? null;

            return ! $shortcut || ! array_key_exists($shortcut, self::SHORTCUTS) || $this->shortcutValue($shortcut, $p) > 0;
        })->values();
    }

    /**
     * @param  Collection<int, Payment>  $items
     * @param  array<int, string>  $keys
     * @return array<int, array{key: string, label: string, options: array<int, string>}>
     */
    public function filterOptions(Collection $items, array $keys): array
    {
        return collect($keys)->map(fn (string $key): array => [
            'key' => $key, 'label' => self::FILTERS[$key],
            'options' => $items->map(fn (Payment $p): ?string => $this->filterValue($key, $p))->filter()->unique()->sort()->values()->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, Payment>  $items
     * @return Collection<int, Payment>
     */
    public function sort(Collection $items, ?string $key): Collection
    {
        $c = fn (Payment $p): array => $this->ledger->calc($p);

        return $items->sort(match ($key) {
            'brut' => fn ($a, $b) => $c($b)['gross'] <=> $c($a)['gross'],
            'commission' => fn ($a, $b) => $c($b)['com'] <=> $c($a)['com'],
            'net' => fn ($a, $b) => $c($b)['net'] <=> $c($a)['net'],
            'promoteur' => fn ($a, $b) => strcmp($a->site->promoter_name, $b->site->promoter_name),
            'chantier' => fn ($a, $b) => strcmp($a->site->name, $b->site->name),
            'statut' => fn ($a, $b) => strcmp($a->pay_status, $b->pay_status),
            'paiement' => fn ($a, $b) => $b->paid_at <=> $a->paid_at,
            'reversement' => fn ($a, $b) => $b->payout_at <=> $a->payout_at,
            default => fn ($a, $b) => $b->date <=> $a->date ?: strcmp($b->reference, $a->reference),
        })->values();
    }

    /**
     * Ligne de tableau d’une opération.
     *
     * @return array<string, mixed>
     */
    public function row(Payment $p): array
    {
        $c = $this->ledger->calc($p);

        return [
            'reference' => $p->reference,
            'date' => $p->date->format('Y-m-d'),
            'type' => $p->type,
            'promoter' => $p->site->promoter_name,
            'site' => $p->site->name,
            'worker' => $p->worker ? ['name' => $p->worker->name, 'code' => $p->worker->code, 'trade' => $p->worker->trade, 'slug' => $p->worker->slug] : null,
            'period' => $p->period_start->format('d/m').' → '.$p->period_end->format('d/m/Y'),
            'gross' => $c['gross'], 'extras' => $c['bonus'] + $c['indemnity'], 'deduction' => $c['deduction'], 'com' => $c['com'], 'comP' => $c['comP'], 'comO' => $c['comO'],
            'fees' => $c['fees'], 'promoterTotal' => $c['promoterTotal'], 'net' => $c['net'], 'comNet' => $c['comNet'], 'comCollected' => $c['comCollected'], 'comRefunded' => $c['comRefunded'],
            'method' => $p->method,
            'payStatus' => PaymentLedger::badge($p->pay_status),
            'payoutStatus' => PaymentLedger::badge($p->payout_status),
            'comStatus' => PaymentLedger::badge($c['comStatus']),
            'reconciliation' => PaymentLedger::badge($p->reconciliation),
            'isDeposit' => $p->isDeposit(),
            'paid' => $c['paid'],
            'canRetry' => $p->payout_status === 'Échoué',
            'rate' => $c['y'] + $c['z'],
        ];
    }

    /**
     * Totaux d’une liste (brut, commissions, net ouvriers).
     *
     * @param  Collection<int, Payment>  $items
     * @return array{gross: int, com: int, net: int}
     */
    public function totals(Collection $items): array
    {
        return [
            'gross' => (int) $items->sum(fn (Payment $p): int => $this->ledger->calc($p)['gross']),
            'com' => (int) $items->sum(fn (Payment $p): int => $this->ledger->calc($p)['com']),
            'net' => (int) $items->sum(fn (Payment $p): int => $this->ledger->calc($p)['net']),
        ];
    }

    /**
     * Pagination simple.
     *
     * @param  Collection<int, mixed>  $items
     * @return array{data: array<int, mixed>, page: int, lastPage: int, total: int, from: int, to: int}
     */
    public static function paginate(Collection $items, int $page, int $size, callable $map): array
    {
        $last = max(1, (int) ceil($items->count() / $size));
        $page = min(max(1, $page), $last);

        return [
            'data' => $items->forPage($page, $size)->values()->map($map)->all(),
            'page' => $page, 'lastPage' => $last, 'total' => $items->count(),
            'from' => $items->isEmpty() ? 0 : ($page - 1) * $size + 1, 'to' => min($items->count(), $page * $size),
        ];
    }

    /**
     * Agrégats de commission par groupe (opérations, volume brut, calculées, validées, encaissées, remboursées, extournées, nettes).
     *
     * @param  Collection<int, Payment>  $items
     * @return array<string, array<string, int>>
     */
    public function group(Collection $items, callable $by): array
    {
        $out = [];
        foreach ($items as $p) {
            $k = (string) $by($p);
            $c = $this->ledger->calc($p);
            $out[$k] ??= ['n' => 0, 'gross' => 0, 'calc' => 0, 'validated' => 0, 'collected' => 0, 'refunded' => 0, 'reversed' => 0, 'net' => 0];
            $out[$k]['n']++;
            $out[$k]['gross'] += $c['gross'];
            $out[$k]['calc'] += $c['comStatus'] === 'Annulée' ? 0 : $c['com'];
            $out[$k]['validated'] += in_array($c['comStatus'], ['À encaisser', 'Encaissée', 'Partiellement encaissée', 'Partiellement remboursée', 'Remboursée', 'Corrigée'], true) ? $c['com'] : 0;
            $out[$k]['collected'] += $c['comCollected'];
            $out[$k]['refunded'] += $c['comRefunded'];
            $out[$k]['reversed'] += $c['comReversed'];
            $out[$k]['net'] += $c['comNet'];
        }

        return $out;
    }

    /**
     * Barre segmentée : total et part de chaque composante.
     *
     * @param  array<int, array{0: string, 1: int, 2: string}>  $items
     * @return array<string, mixed>
     */
    public static function segments(string $title, string $sub, array $items): array
    {
        $total = array_sum(array_column($items, 1));

        return ['title' => $title, 'sub' => $sub, 'total' => $total, 'items' => array_map(fn (array $x): array => ['label' => $x[0], 'value' => $x[1], 'color' => $x[2], 'percent' => $total ? round($x[1] / $total * 100, 1) : 0], $items)];
    }

    /**
     * Navigation du module, avec les compteurs des sections à traiter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(string $current): array
    {
        $all = $this->ledger->all();
        $counts = [
            '/paiements/a-reverser' => $all->filter(fn (Payment $p): bool => ! $p->isDeposit() && $this->ledger->calc($p)['paid'] && in_array($p->payout_status, ['À préparer', 'Validé', 'Échoué', 'À reprendre'], true))->count(),
            '/paiements/remboursements' => $all->sum(fn (Payment $p): int => $p->refunds->where('status', 'Demandé')->count()),
            '/paiements/anomalies' => $all->filter(fn (Payment $p): bool => collect($this->ledger->anomalies($p))->whereIn('level', ['Critique', 'Élevée'])->isNotEmpty())->count(),
        ];

        return collect(self::SECTIONS)->map(fn (array $s, string $href): array => [
            'href' => $href, 'label' => $s[0], 'icon' => $s[1], 'count' => $counts[$href] ?? 0,
            'active' => $current === $href || in_array($current, $s[2], true) || (in_array($href, ['/paiements/operations', '/paiements/commissions'], true) && str_starts_with($current, "{$href}/")),
        ])->values()->all();
    }

    /**
     * Export CSV des opérations (période, auteur et date inclus).
     *
     * @param  Collection<int, Payment>  $items
     */
    public function csv(string $name, Collection $items, string $period): StreamedResponse
    {
        return response()->streamDownload(function () use ($name, $items, $period): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            foreach ([['Rapport', $name], ['Période', $period], ['Généré le', now()->format('d/m/Y H:i')], ['Auteur', auth()->user()?->name ?? 'Système'], ['Enregistrements', $items->count()], []] as $line) {
                fputcsv($out, $line, ';');
            }
            fputcsv($out, ['Référence', 'Date', 'Type', 'Promoteur', 'Chantier', 'Ouvrier', 'Affectation', 'Période', 'Brut', 'Bonus+indemnités', 'Retenues', 'Commission O’CHANTIER', 'Part promoteur', 'Part ouvrier', 'Frais', 'Payé promoteur', 'Net ouvrier', 'Moyen', 'Paiement', 'Reversement', 'Commission', 'Rapprochement'], ';');
            foreach ($items as $p) {
                $c = $this->ledger->calc($p);
                fputcsv($out, [$p->reference, $p->date->format('d/m/Y'), $p->type, $p->site->promoter_name, $p->site->name, $p->worker?->name ?? '—', $p->assignment_reference ?: '—', $p->period_start->format('d/m/Y').' → '.$p->period_end->format('d/m/Y'), $c['gross'], $c['bonus'] + $c['indemnity'], $c['deduction'], $c['com'], $c['comP'], $c['comO'], $c['fees'], $c['promoterTotal'], $c['net'], $p->method ?? '—', $p->pay_status, $p->payout_status, $c['comStatus'], $p->reconciliation], ';');
            }
            fclose($out);
        }, "{$name}-".self::today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
