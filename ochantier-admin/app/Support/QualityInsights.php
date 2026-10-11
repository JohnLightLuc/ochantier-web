<?php

namespace App\Support;

use App\Models\Claim;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Contexte d’analyse d’Avis & litiges pour une requête : période et période précédente, base des litiges (date de
 * création ou de résolution), filtres globaux, prestations évaluables (opérations payées du registre financier),
 * avis, réclamations et litiges retenus, agrégats de qualité et indicateurs de résolution.
 */
class QualityInsights
{
    public const PERIODS = ['jour' => 'Aujourd’hui', 'sem' => 'Cette semaine', 'mois' => 'Ce mois', 'trim' => 'Ce trimestre', 'annee' => 'Cette année', 'perso' => 'Personnalisée'];

    /**
     * Filtres : clé d’URL → libellé, s’applique aux avis, s’applique aux litiges.
     *
     * @var array<string, array{0: string, 1: bool, 2: bool}>
     */
    public const FILTERS = [
        'promoteur' => ['Promoteur', true, true], 'chantier' => ['Chantier', true, true], 'ouvrier' => ['Ouvrier', true, true], 'equipe' => ['Équipe', true, false],
        'metier' => ['Métier', true, true], 'commune' => ['Commune', true, true], 'prestation' => ['Type de prestation', true, true], 'type_avis' => ['Type d’avis', true, false],
        'niveau' => ['Niveau de note', true, false], 'moderation' => ['Statut de modération', true, false], 'categorie' => ['Catégorie de litige', false, true],
        'priorite' => ['Priorité', false, true], 'gravite' => ['Gravité', false, true], 'statut_litige' => ['Statut du litige', false, true], 'responsable' => ['Responsable', false, true],
        'impact' => ['Impact financier', false, true],
    ];

    public const CONTEXT = ['periode', 'du', 'au', 'base', 'tous'];

    /** @var array<string, mixed> */
    public array $period;

    public string $basis;

    /** @var array<string, string> */
    public array $filters;

    public string $q;

    /** @var Collection<int, Review> */
    public Collection $reviews;

    /** @var Collection<int, Dispute> */
    public Collection $disputes;

    /** @var Collection<int, Claim> */
    public Collection $claims;

    /** @var Collection<int, Review> Avis filtrés (toutes dates). */
    public Collection $reviewsScoped;

    /** @var Collection<int, Review> Avis de la période. */
    public Collection $reviewsInPeriod;

    /** @var Collection<int, Dispute> */
    public Collection $disputesScoped;

    /** @var Collection<int, Dispute> */
    public Collection $disputesInPeriod;

    /** @var Collection<int, Claim> */
    public Collection $claimsScoped;

    /** @var Collection<int, array<string, mixed>> */
    public Collection $prestationsScoped;

    /** @var array<string, mixed> Agrégats des avis de la période. */
    public array $a;

    /** @var array<string, mixed> */
    public array $prev;

    /** @var array<string, mixed> Agrégats de toutes les dates (note moyenne de référence du classement). */
    public array $all;

    /** @var Collection<int, array<string, mixed>>|null */
    private static ?Collection $prestations = null;

    /**
     * @param  array<string, mixed>  $query
     */
    public function __construct(public array $query = [])
    {
        $this->basis = ($query['base'] ?? '') === 'res' ? 'res' : 'open';
        $this->period = self::period($query['periode'] ?? null, $query['du'] ?? null, $query['au'] ?? null);
        $this->filters = array_filter(array_intersect_key(array_map(fn ($v): string => (string) $v, $query), self::FILTERS), fn (string $v): bool => $v !== '');
        $this->q = mb_strtolower(trim((string) ($query['q'] ?? '')));
        $this->reviews = Review::with('site', 'worker')->orderByDesc('date')->orderByDesc('id')->get();
        $this->disputes = Dispute::with('site', 'worker', 'promoter')->orderByDesc('opened_at')->orderByDesc('id')->get();
        $this->claims = Claim::with('site', 'dispute')->orderByDesc('date')->orderByDesc('id')->get();
        $p = $this->period;
        $this->reviewsScoped = $this->reviews->filter(fn (Review $r): bool => $this->matches($r, 1))->values();
        $this->disputesScoped = $this->disputes->filter(fn (Dispute $d): bool => $this->matches($d, 2))->values();
        $this->claimsScoped = $this->claims->filter(fn (Claim $c): bool => collect(['chantier' => $c->site->name, 'commune' => $c->site->commune, 'categorie' => $c->category])->every(fn (string $v, string $k): bool => ! isset($this->filters[$k]) || $this->filters[$k] === $v))->values();
        $this->reviewsInPeriod = $this->reviewsScoped->filter(fn (Review $r): bool => $this->in($r->date->format('Y-m-d')))->values();
        $this->disputesInPeriod = $this->disputesScoped->filter(fn (Dispute $d): bool => $this->in($this->basis === 'res' ? $d->issuedAt() : $d->opened_at->format('Y-m-d')))->values();
        $this->prestationsScoped = self::prestations()->filter(fn (array $x): bool => collect(['promoteur' => $x['promoter'], 'chantier' => $x['site'], 'ouvrier' => $x['worker'], 'metier' => $x['trade'], 'commune' => $x['commune'], 'prestation' => $x['prestation']])
            ->every(fn (string $v, string $k): bool => ! isset($this->filters[$k]) || $this->filters[$k] === $v))->values();
        $this->a = self::aggregate($this->reviewsInPeriod, $this->prestationsScoped->filter(fn (array $x): bool => $this->in($x['to']))->values());
        $this->prev = self::aggregate($this->reviewsScoped->filter(fn (Review $r): bool => $this->in($r->date->format('Y-m-d'), $p['previousFrom'], $p['previousTo']))->values(), $this->prestationsScoped->filter(fn (array $x): bool => $this->in($x['to'], $p['previousFrom'], $p['previousTo']))->values());
        $this->all = self::aggregate($this->reviewsScoped, $this->prestationsScoped);
    }

    public static function today(): CarbonImmutable
    {
        return Payment::referenceDate();
    }

    public static function role(): string
    {
        return (string) config('admin.quality.role');
    }

    /**
     * Droit du rôle courant (av, mod, hide, restore, sig, lit, create, qualif, assign, proof, notes, med, dec, approve, fin,
     * rempl, ptc, rec, close, reopen, export, audit).
     */
    public static function can(string $right): bool
    {
        $rights = config('admin.quality.roles')[self::role()] ?? config('admin.quality.roles')['Lecture seule'];

        return $rights === 'all' || in_array($right, $rights, true);
    }

    public static function denied(string $what = ''): string
    {
        return 'Action non autorisée pour le rôle '.self::role().($what ? " · {$what}" : '').'.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function period(?string $key, ?string $from = null, ?string $to = null): array
    {
        $t = self::today();
        $key = array_key_exists((string) $key, self::PERIODS) ? $key : 'annee';
        $date = fn (?string $v): ?CarbonImmutable => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v) : null;
        [$d1, $d2] = match ($key) {
            'jour' => [$t, $t],
            'sem' => [$t->startOfWeek(), $t],
            'mois' => [$t->startOfMonth(), $t],
            'trim' => [$t->firstOfQuarter(), $t],
            'perso' => [$date($from) ?? $t->startOfMonth(), $date($to) ?? $t],
            default => [$t->startOfYear(), $t],
        };
        if ($d1->greaterThan($d2)) {
            [$d1, $d2] = [$d2, $d1];
        }
        $length = (int) $d1->diffInDays($d2) + 1;

        return [
            'key' => $key, 'label' => $key === 'perso' ? 'Période personnalisée' : self::PERIODS[$key], 'from' => $d1->format('Y-m-d'), 'to' => $d2->format('Y-m-d'),
            'previousFrom' => $d1->subDays($length)->format('Y-m-d'), 'previousTo' => $d1->subDay()->format('Y-m-d'), 'range' => $d1->format('d/m/Y').' → '.$d2->format('d/m/Y'),
        ];
    }

    public function in(?string $date, ?string $from = null, ?string $to = null): bool
    {
        return $date !== null && $date >= ($from ?? $this->period['from']) && $date <= ($to ?? $this->period['to']);
    }

    /**
     * Prestations évaluables : opérations payées, éligibles, rattachées à un ouvrier (du plus récent au plus ancien).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function prestations(): Collection
    {
        if (self::$prestations) {
            return self::$prestations;
        }
        $sites = Site::with('promoter')->get()->keyBy('id');

        return self::$prestations = (new FinanceLedger)->entries()
            ->filter(fn (array $x): bool => ! $x['test'] && ! $x['dup'] && $x['elig'] && $x['workerSlug'] && $x['paid'])
            ->map(function (array $x) use ($sites): array {
                $site = $sites->get($x['siteId']);
                $days = max(1, (int) $x['days'] ?: 5);

                return [
                    'opRef' => $x['opRef'], 'paymentRef' => $x['paymentRef'], 'siteId' => $x['siteId'], 'site' => $x['site'], 'siteSlug' => $x['siteSlug'], 'commune' => $x['commune'],
                    'promoterId' => $x['promoterId'], 'promoter' => $x['promoter'], 'promoterSlug' => $x['promoterSlug'], 'author' => $site?->promoter?->representative ?? $site?->manager ?? 'Responsable chantier',
                    'workerId' => $x['workerId'], 'worker' => $x['worker'], 'workerSlug' => $x['workerSlug'], 'trade' => $x['trade'], 'prestation' => $x['prestation'],
                    'to' => $x['d']['presta'], 'from' => CarbonImmutable::parse($x['d']['presta'])->subDays($days - 1)->format('Y-m-d'), 'days' => $days,
                    'amount' => $x['promoterTotal'], 'net' => $x['net'],
                    'assignment' => $x['assignment'] ?? 'AFF-'.substr($x['d']['presta'], 0, 4).'-'.(1000 + crc32($x['workerSlug'].$x['siteSlug']) % 9000),
                ];
            })
            ->sortByDesc('to')->values();
    }

    /**
     * Libellé d’une prestation dans les formulaires.
     *
     * @param  array<string, mixed>  $p
     */
    public static function prestationLabel(array $p): string
    {
        return "{$p['opRef']} · {$p['worker']} · {$p['site']} · ".self::date($p['from']).' → '.self::date($p['to']);
    }

    public static function forgetPrestations(): void
    {
        self::$prestations = null;
    }

    public static function level(?float $score): string
    {
        return match (true) {
            $score === null => 'Non noté',
            $score < 2 => '1 à 2',
            $score < 3 => '2 à 3',
            $score < 4 => '3 à 4',
            default => '4 à 5',
        };
    }

    /**
     * Valeur d’un filtre pour un avis ou un litige (null : filtre sans objet).
     */
    public static function filterValue(string $key, Review|Dispute $x): ?string
    {
        if ($x instanceof Review) {
            return match ($key) {
                'promoteur' => $x->promoter_name, 'chantier' => $x->site->name, 'ouvrier' => $x->target_type === 'Ouvrier' ? $x->target : '', 'equipe' => $x->team ? $x->target : '',
                'metier' => $x->trade, 'commune' => $x->site->commune, 'prestation' => $x->prestation, 'type_avis' => $x->kind, 'niveau' => self::level($x->score()), 'moderation' => $x->status,
                default => null,
            };
        }

        return match ($key) {
            'promoteur' => $x->site->promoter_name, 'chantier' => $x->site->name, 'ouvrier' => $x->worker?->name ?? '', 'metier' => $x->trade, 'commune' => $x->site->commune,
            'prestation' => $x->prestation, 'categorie' => $x->category, 'priorite' => $x->priority, 'gravite' => $x->severity, 'statut_litige' => $x->status,
            'responsable' => $x->owner ?: 'Non affecté', 'impact' => $x->amount || $x->financial_requests ? 'Avec impact financier' : 'Sans impact financier',
            default => null,
        };
    }

    /**
     * Filtres applicables (avis : position 1, litiges : position 2).
     */
    private function matches(Review|Dispute $x, int $applies): bool
    {
        foreach ($this->filters as $key => $value) {
            if (self::FILTERS[$key][$applies] && self::filterValue($key, $x) !== $value) {
                return false;
            }
        }

        return true;
    }

    public function filterSummary(): string
    {
        return collect($this->filters)->map(function (string $v, string $k): string {
            [$label, $reviews, $disputes] = self::FILTERS[$k];

            return "{$label} : {$v}".(! $reviews ? ' (litiges)' : (! $disputes ? ' (avis)' : ''));
        })->implode(' · ');
    }

    /**
     * Listes déroulantes des filtres demandés, avec les valeurs présentes dans les données.
     *
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function filterSelects(array $keys): array
    {
        return array_map(function (string $key): array {
            [$label, $reviews, $disputes] = self::FILTERS[$key];
            $values = collect([...($reviews ? $this->reviews->all() : []), ...($disputes ? $this->disputes->all() : [])])
                ->map(fn (Review|Dispute $x): ?string => self::filterValue($key, $x))->filter(fn (?string $v): bool => $v !== null && $v !== '' && $v !== '—')->unique()->sort(fn ($a, $b) => strcoll($a, $b))->values();

            return ['key' => $key, 'label' => "{$label} : tous", 'value' => $this->filters[$key] ?? '', 'options' => [['value' => '', 'label' => "{$label} : tous"], ...$values->map(fn (string $v): array => ['value' => $v, 'label' => $v])->all()]];
        }, $keys);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function search(Collection $items, callable $text): Collection
    {
        return $this->q === '' ? $items->values() : $items->filter(fn ($x): bool => str_contains(mb_strtolower($text($x)), $this->q))->values();
    }

    /**
     * Lien interne au module qui conserve la période, la base et les filtres.
     *
     * @param  array<string, mixed>  $extra
     */
    public function url(string $path, array $extra = [], bool $keepFilters = true): string
    {
        $query = array_filter([...array_intersect_key($this->query, array_flip(self::CONTEXT)), ...($keepFilters ? $this->filters : []), ...$extra], fn ($v): bool => $v !== null && $v !== '');

        return '/avis-litiges'.$path.($query ? '?'.http_build_query($query) : '');
    }

    /**
     * Agrégats de qualité : avis comptés, note moyenne, exclus, reçus, vérifiés, négatifs, satisfaits, prestations
     * éligibles et prestations évaluées (taux de collecte).
     *
     * @param  Collection<int, Review>  $reviews
     * @param  Collection<int, array<string, mixed>>  $prestations
     * @return array<string, mixed>
     */
    public static function aggregate(Collection $reviews, Collection $prestations): array
    {
        $counted = $reviews->filter(fn (Review $r): bool => $r->isCounted())->values();
        $scores = $counted->map(fn (Review $r): ?float => $r->score())->filter(fn ($s) => $s !== null);
        $rated = $reviews->filter(fn (Review $r): bool => ! $r->is_test && ! $r->duplicate_of && ! in_array($r->status, ['Brouillon', 'Annulé'], true) && $r->kind !== 'Interne')->pluck('operation_reference')->flip();
        $t = self::today()->format('Y-m-d');
        $eligible = $prestations->filter(fn (array $p): bool => $p['to'] <= $t);

        return [
            'counted' => $counted, 'n' => $counted->count(), 'avg' => $scores->isNotEmpty() ? $scores->avg() : null, 'excl' => $reviews->count() - $counted->count(),
            'received' => $reviews->filter(fn (Review $r): bool => ! $r->is_test && $r->status !== 'Brouillon')->count(),
            'verified' => $reviews->filter(fn (Review $r): bool => ! $r->is_test && $r->verification === 'Vérifié')->count(),
            'neg' => $counted->filter(fn (Review $r): bool => $r->score() <= config('admin.quality.negative'))->count(),
            'sat' => $counted->filter(fn (Review $r): bool => $r->score() >= config('admin.quality.satisfaction'))->count(),
            'elig' => $eligible->count(), 'collected' => $eligible->filter(fn (array $p): bool => isset($rated[$p['opRef']]))->count(),
        ];
    }

    /**
     * @param  array<int, int|float>  $values
     */
    public static function median(array $values): ?float
    {
        if (! $values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    public static function days(?CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        return $from && $to ? (int) $from->diffInDays($to, false) : 0;
    }

    /**
     * Indicateurs des litiges : volumes, délais médians, taux (avec dénominateur) et exposition financière.
     *
     * @return array<string, mixed>
     */
    public function disputeKpis(): array
    {
        $scoped = $this->disputesScoped;
        $period = $this->disputesInPeriod;
        $active = $scoped->filter(fn (Dispute $d): bool => $d->isActive());
        $exposures = $active->map(fn (Dispute $d): array => $d->exposure());
        $sum = fn (string $k): int => (int) $exposures->sum($k);
        $date = fn ($d): ?CarbonImmutable => $d ? CarbonImmutable::parse($d) : null;
        $resolved = $scoped->filter(fn (Dispute $d): bool => $d->resolved_at && $this->in($d->resolved_at->format('Y-m-d')));
        $issued = $scoped->filter(fn (Dispute $d): bool => $this->in($d->issuedAt()));
        $mediated = $period->filter(fn (Dispute $d): bool => $d->mediation['status'] !== 'Non proposée');
        $mediable = $period->filter(fn (Dispute $d): bool => $d->isQualified() && ! in_array($d->category, config('admin.quality.no_mediation'), true));
        $mediationsDone = $scoped->filter(fn (Dispute $d): bool => in_array($d->mediation['status'], ['Accord validé', 'Échec', 'Refusée', 'Clôturée'], true));
        $decisions = $scoped->filter(fn (Dispute $d): bool => ($d->decision['status'] ?? null) === 'Rendue');
        $closedEver = $scoped->filter(fn (Dispute $d): bool => $d->resolved_at || $d->closed_at || $d->reopen_count);
        $parties = $scoped->flatMap(fn (Dispute $d): array => [$d->claimant['name'], $d->respondent['name']])->countBy();
        $durations = $resolved->map(fn (Dispute $d): int => self::days($date($d->opened_at), $date($d->resolved_at)))->values()->all();
        $ratio = fn (int $a, int $b): ?float => $b ? $a / $b * 100 : null;

        return [
            'new' => $period->filter(fn (Dispute $d): bool => $d->status !== 'Brouillon' && $this->in($d->opened_at->format('Y-m-d')))->count(),
            'active' => $active, 'activeCount' => $active->count(),
            'critical' => $active->filter(fn (Dispute $d): bool => $d->priority === 'Critique' || $d->severity === 'Critique')->count(),
            'late' => $active->filter(fn (Dispute $d): bool => $d->sla()['status'] === 'En retard')->count(),
            'soon' => $active->filter(fn (Dispute $d): bool => $d->sla()['status'] === 'Bientôt en retard')->count(),
            'mediating' => $scoped->filter(fn (Dispute $d): bool => in_array($d->mediation['status'], ['En cours', 'Acceptée', 'Accord provisoire'], true))->count(),
            'resolved' => $resolved->count(), 'closed' => $scoped->filter(fn (Dispute $d): bool => $d->closed_at && $this->in($d->closed_at->format('Y-m-d')))->count(),
            'firstMedian' => self::median($period->pluck('first_response_hours')->filter(fn ($v) => $v !== null)->all()),
            'resolutionMedian' => self::median($durations), 'resolutionAverage' => $durations ? array_sum($durations) / count($durations) : null,
            'qualificationMedian' => self::median($period->filter(fn (Dispute $d): bool => (bool) $d->qualified_at)->map(fn (Dispute $d): int => self::days($date($d->opened_at), $date($d->qualified_at)))->values()->all()),
            'executionMedian' => self::median($scoped->flatMap(fn (Dispute $d): array => collect($d->financial_requests)->filter(fn (array $f): bool => ($f['executed_at'] ?? null) && $this->in($f['executed_at']))->map(fn (array $f): int => self::days($date($f['at']), $date($f['executed_at'])))->all())->values()->all()),
            'exposure' => $sum('amount'), 'exposureNet' => $sum('net'), 'suspended' => $sum('suspended'), 'refunds' => $sum('refunds'), 'adjustments' => $sum('adjustments'), 'commissions' => $sum('commissions'),
            'done' => (int) $scoped->sum(fn (Dispute $d): int => $d->exposure()['done']),
            'resolutionRate' => $ratio($resolved->count(), $issued->count()), 'resolutionDen' => $issued->count(),
            'mediationRate' => $ratio($mediated->count(), $mediable->count()), 'mediationDen' => $mediable->count(),
            'agreementRate' => $ratio($mediationsDone->filter(fn (Dispute $d): bool => $d->mediation['status'] === 'Accord validé')->count(), $mediationsDone->count()), 'agreementDen' => $mediationsDone->count(),
            'appealRate' => $ratio($decisions->filter(fn (Dispute $d): bool => (bool) $d->appeals)->count(), $decisions->count()), 'appealDen' => $decisions->count(),
            'reopenRate' => $ratio($scoped->filter(fn (Dispute $d): bool => (bool) $d->reopen_count)->count(), $closedEver->count()), 'reopenDen' => $closedEver->count(),
            'recurrenceRate' => $ratio($parties->filter(fn (int $n): bool => $n > 1)->count(), $parties->count()), 'recurrenceDen' => $parties->count(),
        ];
    }

    /**
     * Files d’avis à traiter : à modérer, signalés ou contestés, réponses à modérer, détections suspectes.
     *
     * @return array<string, Collection<int, Review>>
     */
    public function reviewQueues(): array
    {
        $s = $this->reviewsScoped;

        return [
            'pending' => $s->filter(fn (Review $r): bool => in_array($r->status, Review::PENDING, true) && ! $r->is_test)->values(),
            'flagged' => $s->filter(fn (Review $r): bool => in_array($r->status, ['Signalé', 'Contesté'], true) || collect($r->reports)->contains(fn (array $g): bool => $g['status'] !== 'Traité'))->values(),
            'replies' => $s->filter(fn (Review $r): bool => in_array($r->reply['status'] ?? '', ['Soumise', 'En attente de modération'], true))->values(),
            'suspects' => $s->filter(fn (Review $r): bool => collect($r->detections)->contains('status', 'À examiner'))->values(),
        ];
    }

    public static function money(int|float|null $v): string
    {
        return FinanceInsights::money($v);
    }

    public static function percent(int|float|null $v): string
    {
        return FinanceInsights::percent($v);
    }

    public static function ratio(int|float|null $a, int|float|null $b): ?float
    {
        return FinanceInsights::ratio($a, $b);
    }

    public static function date(?string $d): string
    {
        return FinanceInsights::date($d);
    }

    public static function one(int|float|null $v): string
    {
        return $v === null ? '—' : str_replace('.', ',', (string) round((float) $v, 1));
    }

    public static function two(int|float|null $v): string
    {
        return $v === null ? '—' : number_format((float) $v, 2, ',', '');
    }

    public static function mask(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 4 ? substr($d, 0, 2).' •• •• •• '.substr($d, -2) : 'Non communiqué';
    }
}
