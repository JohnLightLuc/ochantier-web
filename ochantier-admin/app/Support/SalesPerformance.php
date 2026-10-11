<?php

namespace App\Support;

use App\Enums\SalesAgentStatus;
use App\Models\SalesAgent;
use App\Models\SalesRanking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Performances mensuelles des commerciaux : métriques, score sur 100, classement et gains.
 *
 * Les métriques (ouvriers enregistrés, dossiers complets, profils validés…) sont calculées de façon
 * déterministe comme dans la maquette ; elles seront remplacées par les comptages réels du module Ouvriers.
 * Le score, le classement et les gains appliquent déjà les règles définitives.
 */
class SalesPerformance
{
    /**
     * Part du mois en cours écoulée, pour proratiser les objectifs.
     */
    private const CURRENT_MONTH_PRORATA = 0.77;

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $rankings = [];

    /** @var Collection<string, SalesRanking>|null */
    private ?Collection $states = null;

    /** @var Collection<int, SalesAgent>|null */
    private ?Collection $agents = null;

    public static function currentMonth(): string
    {
        return config('admin.sales_current_month');
    }

    /**
     * Date de référence des données de démonstration (« aujourd’hui » pour l’inactivité et les objectifs).
     */
    public static function referenceDate(): Carbon
    {
        return Carbon::parse(config('admin.sales_reference_date'))->startOfDay();
    }

    public static function previousMonth(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Mois classés, du plus récent au plus ancien (janvier à mois en cours).
     *
     * @return array<int, string>
     */
    public static function months(): array
    {
        $current = Carbon::createFromFormat('Y-m', self::currentMonth())->startOfMonth();

        return collect(range(0, $current->month - 1))->map(fn (int $i): string => $current->copy()->subMonths($i)->format('Y-m'))->all();
    }

    public static function monthLabel(string $month): string
    {
        return ucfirst(Carbon::createFromFormat('Y-m', $month)->startOfMonth()->translatedFormat('F Y'));
    }

    public static function performanceLabel(?float $score): string
    {
        return match (true) {
            $score === null => '—',
            $score >= 85 => 'Excellent',
            $score >= 75 => 'Très bon',
            $score >= 65 => 'Satisfaisant',
            $score >= 55 => 'À surveiller',
            default => 'Insuffisant',
        };
    }

    public static function performanceTone(string $label): string
    {
        return ['Excellent' => 'g', 'Très bon' => 'b', 'Satisfaisant' => 'a', 'À surveiller' => 'o', 'Insuffisant' => 'r'][$label] ?? 'n';
    }

    /**
     * Le classement du mois est-il clôturé ? Les mois passés le sont par défaut.
     */
    public function isClosed(string $month): bool
    {
        $state = $this->states()->get($month);

        return $state?->closed_at !== null || $month < self::currentMonth();
    }

    public function isPublished(string $month): bool
    {
        return (bool) $this->states()->get($month)?->published;
    }

    /**
     * @return Collection<string, SalesRanking>
     */
    private function states(): Collection
    {
        return $this->states ??= SalesRanking::all()->keyBy('month');
    }

    /**
     * @return Collection<int, SalesAgent>
     */
    private function agents(): Collection
    {
        return $this->agents ??= SalesAgent::all();
    }

    /**
     * Métriques d’un commercial sur un mois, ou null s’il n’est pas classé ce mois-là.
     *
     * @return array<string, int|string>|null
     */
    public function metrics(SalesAgent $agent, string $month): ?array
    {
        if (! $agent->status->wasActivated() || $agent->started_at->format('Y-m') > $month) {
            return null;
        }
        if ($agent->ranking_until && $month > $agent->ranking_until) {
            return null;
        }

        $h = crc32($agent->slug.$month);
        $partial = $month === self::currentMonth() ? self::CURRENT_MONTH_PRORATA : 1;
        $registered = max(4, (int) round($agent->recruited_total / 12 * (0.7 + ($h % 60) / 100) * $partial));
        $complete = (int) round($registered * (0.72 + ($h % 22) / 100));
        $frauds = $h % 3;
        $validated = max(0, (int) round($complete * (0.78 + (($h >> 3) % 20) / 100)) - $frauds) + (int) ($agent->score_adjustments[$month] ?? 0);
        $rejected = max(0, $registered - $validated - (int) round(($registered - $complete) * 0.6));

        return [
            'registered' => $registered,
            'complete' => $complete,
            'incomplete' => $registered - $complete,
            'validated' => $validated,
            'rejected' => $rejected,
            'frauds' => $frauds,
            'validationRate' => (int) round($validated / max(1, $registered) * 100),
            'objective' => min(100, (int) round($validated / max(1, $agent->monthly_target * $partial) * 100)),
            'quality' => 68 + $h % 29,
            'activity' => 62 + ($h >> 5) % 36,
            'delays' => 58 + ($h >> 7) % 40,
            'averageDelay' => str_replace('.', ',', number_format(1 + ($h % 30) / 10, 1)).' j',
        ];
    }

    /**
     * Classement du mois : métriques normalisées sur 100, score pondéré, rang.
     *
     * Départage : taux de validation, profils validés, qualité, délais, puis taux de rejet le plus faible.
     *
     * @return Collection<int, array{agent: SalesAgent, metrics: array<string, int|string>, normalized: array<string, int>, score: float, rank: int}>
     */
    public function ranking(string $month): Collection
    {
        if (isset($this->rankings[$month])) {
            return $this->rankings[$month];
        }

        $rows = $this->agents()
            ->map(fn (SalesAgent $agent): array => ['agent' => $agent, 'metrics' => $this->metrics($agent, $month)])
            ->filter(fn (array $row): bool => $row['metrics'] !== null)
            ->values();

        $best = max(1, (int) $rows->max(fn (array $r): int => $r['metrics']['validated']));
        $weights = config('admin.sales_score_weights');

        $rows = $rows->map(function (array $row) use ($best, $weights): array {
            $m = $row['metrics'];
            $normalized = [
                'validated' => (int) round($m['validated'] / $best * 100),
                'validation_rate' => min(100, $m['validationRate']),
                'objective' => $m['objective'],
                'quality' => $m['quality'],
                'activity' => $m['activity'],
                'delays' => $m['delays'],
            ];
            $score = round(collect($weights)->sum(fn (array $w, string $key): float => $normalized[$key] * $w[1] / 100), 1);

            return [...$row, 'normalized' => $normalized, 'score' => $score];
        });

        $sorted = $rows->sort(function (array $a, array $b): int {
            $ma = $a['metrics'];
            $mb = $b['metrics'];

            return [$b['score'], $mb['validationRate'], $mb['validated'], $mb['quality'], $mb['delays'], $ma['rejected'] / max(1, $ma['registered'])]
                <=> [$a['score'], $ma['validationRate'], $ma['validated'], $ma['quality'], $ma['delays'], $mb['rejected'] / max(1, $mb['registered'])];
        })->values()->map(fn (array $row, int $i): array => [...$row, 'rank' => $i + 1]);

        return $this->rankings[$month] = $sorted;
    }

    /**
     * Ligne de classement d’un commercial pour un mois, ou null.
     *
     * @return array<string, mixed>|null
     */
    public function row(SalesAgent $agent, string $month): ?array
    {
        return $this->ranking($month)->first(fn (array $r): bool => $r['agent']->is($agent));
    }

    /**
     * Premier commercial éligible au titre de Commercial du mois.
     *
     * @return array<string, mixed>|null
     */
    public function topEligible(string $month): ?array
    {
        return $this->ranking($month)->first(fn (array $r): bool => $r['agent']->status->isEligibleForRanking());
    }

    /**
     * Gain du mois : fixe + commissions + bonus d’objectif + bonus Commercial du mois (mois clôturé), plafonné.
     *
     * @param  array<string, mixed>|null  $row
     */
    public function gain(SalesAgent $agent, string $month, ?array $row): int
    {
        if (! $row) {
            return 0;
        }

        $pay = $agent->pay;
        $cotm = $row['rank'] === 1 && $this->isClosed($month) ? (int) $pay['bonus_cotm'] : 0;
        $fixed = $pay['mode'] === 'Commission' ? 0 : (int) $pay['fixed'];
        $total = $fixed + $row['metrics']['registered'] * $pay['per_registered'] + $row['metrics']['validated'] * $pay['per_validated'] + ($row['metrics']['objective'] >= 100 ? $pay['bonus_objective'] : 0) + $cotm;

        return (int) min($pay['cap'] + $cotm, $total);
    }

    /**
     * Repères de la liste : objectif atteint à date, commercial à relancer, gain du mois précédent à valider.
     *
     * @return array{objective: bool, followUp: bool, earnings: bool, pending: int}
     */
    public function flags(SalesAgent $agent): array
    {
        $row = $this->row($agent, self::currentMonth());
        $previousMonth = self::previousMonth(self::currentMonth());
        $previous = $this->row($agent, $previousMonth);
        $active = $agent->status === SalesAgentStatus::Active;
        $performance = self::performanceLabel($row['score'] ?? null);
        $ref = $this->payoutReference($agent, $previousMonth);
        $pendingStatus = $agent->payout_statuses[$ref] ?? 'En attente de validation';
        $pending = $previous && $agent->status !== SalesAgentStatus::Suspended && ! in_array($pendingStatus, ['Validé', 'Payé', 'Suspendu', 'Programmé'], true)
            ? $this->gain($agent, $previousMonth, $previous) : 0;

        return [
            'objective' => $row && $active && $row['metrics']['validated'] >= round($agent->monthly_target * self::CURRENT_MONTH_PRORATA),
            'followUp' => ($active && ($agent->last_activity_on?->lt(self::referenceDate()->subDays(3)) || in_array($performance, ['À surveiller', 'Insuffisant'], true)))
                || in_array($agent->status, [SalesAgentStatus::Invited, SalesAgentStatus::PendingActivation], true),
            'earnings' => $pending > 0,
            'pending' => $pending,
        ];
    }

    public function payoutReference(SalesAgent $agent, string $month): string
    {
        return 'GC-'.substr($agent->code, 4).'-'.str_replace('-', '', $month);
    }

    /**
     * Lignes de gain mensuelles des six derniers mois, avec leur statut de paiement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payoutLines(SalesAgent $agent): array
    {
        $lines = [];

        foreach (array_slice(self::months(), 0, 6) as $i => $month) {
            $row = $this->row($agent, $month);
            if (! $row) {
                continue;
            }
            $pay = $agent->pay;
            $ref = $this->payoutReference($agent, $month);
            $default = match ($i) {
                0 => 'En calcul',
                1 => $agent->status === SalesAgentStatus::Suspended ? 'Suspendu' : 'En attente de validation',
                5 => 'Corrigé',
                default => 'Payé',
            };
            $status = $agent->payout_statuses[$ref] ?? $default;
            $bonus = ($row['metrics']['objective'] >= 100 ? (int) $pay['bonus_objective'] : 0) + ($row['rank'] === 1 && $this->isClosed($month) ? (int) $pay['bonus_cotm'] : 0);

            $lines[] = [
                'ref' => $ref,
                'month' => $month,
                'period' => self::monthLabel($month),
                'type' => $pay['mode'],
                'profiles' => $row['metrics']['validated'],
                'fixed' => $pay['mode'] === 'Commission' ? 0 : (int) $pay['fixed'],
                'commissions' => $row['metrics']['registered'] * $pay['per_registered'] + $row['metrics']['validated'] * $pay['per_validated'],
                'bonus' => $bonus,
                'deduction' => 0,
                'net' => $this->gain($agent, $month, $row),
                'status' => $status,
                'paidAt' => in_array($status, ['Payé', 'Corrigé'], true) ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()->addMonth()->day(5)->format('Y-m-d') : null,
                'method' => $pay['method'],
            ];
        }

        return $lines;
    }
}
