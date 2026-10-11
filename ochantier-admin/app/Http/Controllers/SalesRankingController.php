<?php

namespace App\Http\Controllers;

use App\Enums\SalesAgentActivityCategory;
use App\Models\SalesRanking;
use App\Support\SalesPerformance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Classement mensuel des commerciaux : podium, tableau complet, publication et clôture.
 */
class SalesRankingController extends Controller
{
    public function show(SalesPerformance $performance, ?string $year = null, ?string $month = null): Response
    {
        $period = $this->period($year, $month);
        $previousMonth = SalesPerformance::previousMonth($period);
        $hasPrevious = in_array($previousMonth, SalesPerformance::months(), true);
        $top = $performance->topEligible($period);
        $isCurrent = $period === SalesPerformance::currentMonth();

        $rows = $performance->ranking($period)->map(function (array $r) use ($period, $previousMonth, $hasPrevious, $performance): array {
            $a = $r['agent'];
            $previous = $hasPrevious ? $performance->row($a, $previousMonth) : null;
            $label = SalesPerformance::performanceLabel($r['score']);

            return [
                'rank' => $r['rank'],
                'evolution' => $previous ? $previous['rank'] - $r['rank'] : null,
                'slug' => $a->slug,
                'name' => $a->name,
                'firstName' => $a->first_name,
                'code' => $a->code,
                'status' => ['value' => $a->status->value, 'label' => $a->status->label()],
                'eligible' => $a->status->isEligibleForRanking(),
                'agency' => $a->agencyLabel(),
                'zone' => $a->zone,
                'supervisor' => $a->supervisor,
                'type' => $a->type,
                'bonusCotm' => (int) $a->pay['bonus_cotm'],
                'score' => $r['score'],
                'performance' => ['label' => $label, 'tone' => SalesPerformance::performanceTone($label)],
                'metrics' => $r['metrics'],
                'normalized' => $r['normalized'],
                'gain' => $performance->gain($a, $period, $r),
            ];
        })->values()->all();

        return Inertia::render('SalesAgents/Ranking', [
            'month' => $period,
            'label' => SalesPerformance::monthLabel($period),
            'closed' => $performance->isClosed($period),
            'published' => $performance->isPublished($period),
            'isCurrent' => $isCurrent,
            'lastCalculation' => $isCurrent ? SalesPerformance::referenceDate()->translatedFormat('d M Y').' · 23:00' : 'Clôture du mois',
            'referenceDate' => SalesPerformance::referenceDate()->format('Y-m-d'),
            'rows' => $rows,
            'topSlug' => $top['agent']->slug ?? null,
            'months' => array_map(fn (string $m): array => ['value' => $m, 'label' => SalesPerformance::monthLabel($m)], SalesPerformance::months()),
            'history' => collect(SalesPerformance::months())->reject(fn (string $m): bool => $m === $period)->take(5)->map(function (string $m) use ($performance): array {
                $t = $performance->topEligible($m);

                return ['month' => $m, 'label' => SalesPerformance::monthLabel($m), 'name' => $t['agent']->name ?? null, 'score' => $t['score'] ?? null, 'validated' => $t['metrics']['validated'] ?? null, 'closed' => $performance->isClosed($m)];
            })->values()->all(),
            'weights' => collect(config('admin.sales_score_weights'))->map(fn (array $w, string $k): array => ['key' => $k, 'label' => $w[0], 'weight' => $w[1]])->values()->all(),
            'header' => $this->header('Classement des commerciaux', SalesPerformance::monthLabel($period).' · score de performance sur 100', [
                ['Commerciaux', '/commerciaux'],
                ['Classement', $isCurrent ? null : '/commerciaux/classement'],
                ...($isCurrent ? [] : [[SalesPerformance::monthLabel($period)]]),
            ]),
        ]);
    }

    public function export(string $year, string $month, SalesPerformance $performance): StreamedResponse
    {
        $period = $this->period($year, $month);
        $ranking = $performance->ranking($period);

        return response()->streamDownload(function () use ($ranking, $period, $performance): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Rang', 'Commercial', 'Agence', 'Zone', 'Enregistrés', 'Complets', 'Validés', 'Taux de validation', 'Objectif', 'Activité', 'Qualité', 'Score', 'Gain (FCFA)'], ';');
            foreach ($ranking as $r) {
                $a = $r['agent'];
                $m = $r['metrics'];
                fputcsv($out, [$r['rank'], $a->name, $a->agencyLabel(), $a->zone, $m['registered'], $m['complete'], $m['validated'], $m['validationRate'], $m['objective'], $m['activity'], $m['quality'], $r['score'], $performance->gain($a, $period, $r)], ';');
            }
            fclose($out);
        }, "classement-{$period}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Publie ou masque le classement dans l’Application Commercial.
     */
    public function togglePublication(string $year, string $month, SalesPerformance $performance): RedirectResponse
    {
        $period = $this->period($year, $month);
        $state = SalesRanking::firstOrNew(['month' => $period]);
        $state->published = ! $state->published;
        $state->save();

        $top = $performance->topEligible($period);
        ($top['agent'] ?? null)?->logActivity(SalesAgentActivityCategory::Ranking, $state->published ? 'a publié le classement' : 'a masqué le classement', null, SalesPerformance::monthLabel($period));
        Inertia::flash('toast', $state->published ? 'Classement publié.' : 'Classement masqué.');

        return back();
    }

    /**
     * Fige le classement et désigne le Commercial du mois.
     */
    public function close(string $year, string $month, SalesPerformance $performance): RedirectResponse
    {
        $period = $this->period($year, $month);
        abort_if($performance->isClosed($period), 422, 'Ce classement est déjà clôturé.');

        SalesRanking::updateOrCreate(['month' => $period], ['closed_at' => now()]);
        if ($top = $performance->topEligible($period)) {
            $agent = $top['agent'];
            $agent->rewards()->create(['month' => $period, 'label' => 'Commercial du mois · '.SalesPerformance::monthLabel($period), 'amount' => (int) $agent->pay['bonus_cotm']]);
            $agent->logActivity(SalesAgentActivityCategory::Ranking, 'a été désigné Commercial du mois', null, SalesPerformance::monthLabel($period), 'Score '.str_replace('.', ',', (string) $top['score']));
        }
        Inertia::flash('toast', 'Classement clôturé.');

        return back();
    }

    /**
     * Mois demandé (année / mois), ou mois en cours ; 404 hors période classée.
     */
    private function period(?string $year, ?string $month): string
    {
        if (! $year || ! $month) {
            return SalesPerformance::currentMonth();
        }
        abort_unless(ctype_digit($year) && ctype_digit($month), 404);
        $period = Carbon::create((int) $year, (int) $month, 1)->format('Y-m');
        abort_unless(in_array($period, SalesPerformance::months(), true), 404);

        return $period;
    }
}
