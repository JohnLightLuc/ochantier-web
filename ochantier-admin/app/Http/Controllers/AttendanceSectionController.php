<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Support\AttendanceCalculator as Calc;
use App\Support\AttendanceInsights as Insights;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sous-pages du module Pointages : incomplets, anomalies, corrections, absences, calendrier et rapports
 * (période par défaut : le mois en cours).
 */
class AttendanceSectionController extends Controller
{
    /**
     * Motifs d’incomplétude affichés en tuiles : libellé → icône.
     *
     * @var array<string, string>
     */
    private const INCOMPLETE = [
        'Arrivée enregistrée non validée' => 'login', 'Arrivée validée sans départ' => 'logout', 'Départ enregistré non validé' => 'pending', 'Preuve manquante' => 'image_not_supported',
        'Localisation manquante' => 'location_off', 'Durée incohérente' => 'timelapse', 'Journée non clôturée' => 'lock_open', 'Aucun pointage ni absence' => 'help',
    ];

    private const ABSENCE_STATUSES = ['En attente de validation' => ['pending', 'a'], 'Validée' => ['verified', 'g'], 'Justifiée' => ['task_alt', 'g'], 'Non justifiée' => ['block', 'r'], 'Régularisée' => ['gavel', 'p']];

    private const CORRECTION_STATUSES = ['Brouillon', 'Soumise', 'En attente', 'Approuvée', 'Rejetée', 'Appliquée', 'Annulée'];

    /**
     * Période, recherche et pointages de la période.
     *
     * @return array{0: Collection<int, array{0: Attendance, 1: array<string, mixed>}>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function scope(Request $request, array $extra = []): array
    {
        $filters = $request->only(['periode', 'du', 'au', 'q', 'page', ...array_keys(Insights::FILTERS), ...$extra]);
        $key = array_key_exists($filters['periode'] ?? '', Insights::PERIODS) ? $filters['periode'] : 'mois';
        [$from, $to] = Insights::period($key, $filters['du'] ?? null, $filters['au'] ?? null);

        return [Insights::load($from, $to), $filters, ['key' => $key, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $from->equalTo($to) ? Insights::dateLabel($from) : Insights::dateLabel($from).' → '.Insights::dateLabel($to)]];
    }

    /**
     * Propriétés communes : navigation, période, filtres actifs.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function common(string $path, array $filters, array $period): array
    {
        return ['sections' => Insights::sections($path), 'period' => $period, 'periods' => Insights::PERIODS, 'filters' => array_filter($filters), 'options' => Insights::options()];
    }

    private function tile(string $label, int|string $value, string $icon, string $tone, ?string $sub = null, ?float $bar = null): array
    {
        return array_filter(['label' => $label, 'value' => (string) $value, 'icon' => $icon, 'tone' => $tone, 'sub' => $sub, 'bar' => $bar], fn ($v): bool => $v !== null);
    }

    public function incomplete(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request);
        $keys = ['chantier', 'promoteur', 'metier', 'arrivee', 'depart'];
        $rows = Insights::sort(Insights::filter($period, $filters, $keys), 'date')->filter(fn (array $x): bool => (bool) $x[1]['incomplete'])->values();
        $count = fn (string $label): int => $rows->filter(fn (array $x): bool => in_array($label, $x[1]['incomplete'], true))->count();

        return Inertia::render('Attendances/Incomplete', [
            ...$this->common('/pointages/incomplets', $filters, $meta),
            'tiles' => collect(self::INCOMPLETE)->map(fn (string $icon, string $label): array => $this->tile($label, $count($label), $icon, $count($label) ? 'warning' : 'neutral'))->values()->all(),
            'filterDefs' => Insights::filterOptions($period, $keys),
            'refs' => $rows->map(fn (array $x): string => $x[0]->reference)->all(),
            'attendances' => Insights::paginate($rows, (int) ($filters['page'] ?? 1), 8, fn (array $x): array => Insights::row($x[0], $x[1])),
            'header' => $this->header('Pointages incomplets', 'Journées dont l’arrivée ou le départ n’est pas enregistré, validé ou clôturé', [['Pointages', '/pointages'], ['Incomplets']]),
        ]);
    }

    public function anomalies(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request, ['type', 'niveau', 'statut']);
        $levels = AttendanceController::LEVELS;
        $all = Insights::filter($period, $filters, [])->flatMap(fn (array $x) => $x[0]->anomalies->map(fn (AttendanceAnomaly $a): array => [$x[0], $a]));
        $list = $all->filter(fn (array $x): bool => (! filled($filters['type'] ?? null) || $x[1]->type === $filters['type'])
                && (! filled($filters['niveau'] ?? null) || $x[1]->level === $filters['niveau'])
                && (! filled($filters['statut'] ?? null) || $x[1]->status === $filters['statut'])
                && (! filled($filters['chantier'] ?? null) || $x[0]->site->name === $filters['chantier']))
            ->sort(fn (array $a, array $b): int => array_search($b[1]->level, $levels, true) <=> array_search($a[1]->level, $levels, true) ?: $b[0]->date <=> $a[0]->date)
            ->values();
        $open = fn (AttendanceAnomaly $a): bool => ! in_array($a->status, Calc::ANOMALY_DONE, true);

        return Inertia::render('Attendances/Anomalies', [
            ...$this->common('/pointages/anomalies', $filters, $meta),
            'tiles' => [
                ...collect(array_reverse($levels))->map(fn (string $l): array => $this->tile($l, $all->filter(fn (array $x): bool => $x[1]->level === $l && $open($x[1]))->count(), 'report', ['Critique' => 'danger', 'Élevée' => 'orange', 'Modérée' => 'warning', 'Faible' => 'neutral', 'Information' => 'info'][$l]))->all(),
                $this->tile('Traitées', $all->reject(fn (array $x): bool => $open($x[1]))->count(), 'task_alt', 'success'),
            ],
            'filterDefs' => [
                ['key' => 'type', 'label' => 'Type', 'options' => $all->map(fn (array $x): string => $x[1]->type)->unique()->sort()->values()->all()],
                ['key' => 'niveau', 'label' => 'Niveau', 'options' => $levels],
                ['key' => 'statut', 'label' => 'Statut', 'options' => AttendanceController::ANOMALY_STATUSES],
                ['key' => 'chantier', 'label' => 'Chantier', 'options' => $all->map(fn (array $x): string => $x[0]->site->name)->unique()->sort()->values()->all()],
            ],
            'anomalies' => Insights::paginate($list, (int) ($filters['page'] ?? 1), 8, fn (array $x): array => [
                'id' => $x[1]->id, 'type' => $x[1]->type, 'level' => $x[1]->level, 'status' => $x[1]->status, 'note' => $x[1]->note, 'decision' => $x[1]->decision, 'detectedOn' => $x[1]->detected_on->format('Y-m-d'),
                'reference' => $x[0]->reference, 'worker' => ['name' => $x[0]->worker->name, 'code' => $x[0]->worker->code], 'site' => $x[0]->site->name,
            ]),
            'header' => $this->header('Anomalies de pointage', 'Détection automatique et signalements · les anomalies ne suppriment jamais un pointage', [['Pointages', '/pointages'], ['Anomalies']]),
        ]);
    }

    public function corrections(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request, ['statut']);
        $list = Insights::filter($period, $filters, [])
            ->flatMap(fn (array $x) => $x[0]->corrections->map(fn (AttendanceCorrection $c): array => [$x[0], $c]))
            ->filter(fn (array $x): bool => ! filled($filters['statut'] ?? null) || $x[1]->status === $filters['statut'])
            ->sortByDesc(fn (array $x) => $x[1]->requested_at)
            ->values();

        return Inertia::render('Attendances/Corrections', [
            ...$this->common('/pointages/corrections', $filters, $meta),
            'filterDefs' => [['key' => 'statut', 'label' => 'Statut', 'options' => self::CORRECTION_STATUSES]],
            'corrections' => Insights::paginate($list, (int) ($filters['page'] ?? 1), 8, fn (array $x): array => [
                'id' => $x[1]->id, 'field' => $x[1]->field, 'oldValue' => $x[1]->old_value, 'newValue' => $x[1]->new_value, 'motif' => $x[1]->motif, 'comment' => $x[1]->comment, 'proof' => $x[1]->proof,
                'status' => $x[1]->status, 'requestedBy' => $x[1]->requested_by, 'requestedAt' => $x[1]->requested_at->format('Y-m-d H:i'), 'decision' => $x[1]->decision, 'impact' => $x[1]->impact,
                'reference' => $x[0]->reference, 'worker' => $x[0]->worker->name, 'site' => $x[0]->site->name,
            ]),
            'header' => $this->header('Corrections de pointage', 'Demandes soumises, approuvées, rejetées et appliquées', [['Pointages', '/pointages'], ['Corrections']]),
        ]);
    }

    public function absences(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request);
        $keys = ['chantier', 'promoteur', 'metier'];
        $rows = Insights::sort(Insights::filter($period, $filters, $keys), 'date')->filter(fn (array $x): bool => (bool) $x[0]->absence)->values();
        $count = fn (string $status): int => $rows->filter(fn (array $x): bool => $x[0]->absence['status'] === $status)->count();

        return Inertia::render('Attendances/Absences', [
            ...$this->common('/pointages/absences', $filters, $meta),
            'tiles' => [
                $this->tile('Absences', $rows->count(), 'person_off', 'danger'),
                ...collect(self::ABSENCE_STATUSES)->map(fn (array $s, string $label): array => $this->tile($label === 'En attente de validation' ? $label : $label.'s', $count($label), $s[0], ['a' => 'warning', 'g' => 'success', 'r' => 'danger', 'p' => 'purple'][$s[1]]))->values()->all(),
            ],
            'filterDefs' => Insights::filterOptions($period->filter(fn (array $x): bool => (bool) $x[0]->absence), $keys),
            'absences' => Insights::paginate($rows, (int) ($filters['page'] ?? 1), 8, fn (array $x): array => [
                ...Insights::row($x[0], $x[1]),
                'absence' => $x[0]->absence,
                'decision' => ['key' => 'absence', 'label' => 'Absence', 'status' => $x[0]->absence['status'], 'options' => AttendanceController::DECISIONS['absence'][1]],
            ]),
            'header' => $this->header('Absences', 'Déclarées par les promoteurs · validation, justification et régularisation', [['Pointages', '/pointages'], ['Absences']]),
        ]);
    }

    /**
     * Calendrier des présences : un ouvrier par ligne, les 14 derniers jours de la période en colonnes.
     */
    public function calendar(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request);
        $keys = ['chantier', 'promoteur', 'metier'];
        $items = Insights::filter($period, $filters, $keys);

        return Inertia::render('Attendances/Calendar', [
            ...$this->common('/pointages/calendrier', $filters, $meta),
            'filterDefs' => Insights::filterOptions($period, $keys),
            ...self::grid($items, 14),
            'header' => $this->header('Calendrier des présences', 'Vue par ouvrier et par jour', [['Pointages', '/pointages'], ['Calendrier']]),
        ]);
    }

    /**
     * Grille de présence (calendrier, vue hebdomadaire d’un chantier).
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @return array{days: array<int, string>, grid: array<int, array<string, mixed>>}
     */
    public static function grid(Collection $items, int $limit, bool $siteInSub = true): array
    {
        $days = $items->map(fn (array $x): string => $x[0]->date->format('Y-m-d'))->unique()->sort()->values()->slice(-$limit)->values();
        $grid = $items->groupBy(fn (array $x): int => $x[0]->worker_id)
            ->map(function (Collection $list) use ($days, $siteInSub): array {
                $first = $list->first()[0];
                $byDay = $list->keyBy(fn (array $x): string => $x[0]->date->format('Y-m-d'));

                return [
                    'worker' => ['name' => $first->worker->name, 'slug' => $first->worker->slug, 'sub' => $first->worker->trade.($siteInSub ? ' · '.$first->site->name : '')],
                    'cells' => $days->map(function (string $d) use ($byDay): ?array {
                        $x = $byDay->get($d);
                        if (! $x) {
                            return null;
                        }
                        [$p, $c] = $x;

                        return ['reference' => $p->reference, 'code' => self::code($c).($c['lateFlag'] ? ' R' : ''), 'tone' => Calc::tone($c['status'])];
                    })->all(),
                    'net' => Calc::duration((int) $list->filter(fn (array $x): bool => $days->contains($x[0]->date->format('Y-m-d')))->sum(fn (array $x): int => $x[1]['net'] ?? 0)),
                ];
            })
            ->sortBy(fn (array $r): string => $r['worker']['name'])->values()->all();

        return ['days' => $days->all(), 'grid' => $grid];
    }

    /**
     * Code d’une journée : P présent, P… en cours, A absent, ? incomplet, · à venir.
     *
     * @param  array<string, mixed>  $c
     */
    private static function code(array $c): string
    {
        return match (true) {
            $c['status'] === 'Clôturée' => $c['arrivalValid'] ? 'P' : 'A',
            $c['arrivalValid'] && $c['departureValid'] => 'P',
            $c['arrivalValid'] => 'P…',
            in_array($c['status'], ['Absent', 'Absence en attente'], true) => 'A',
            $c['status'] === 'À venir' => '·',
            default => '?',
        };
    }

    /**
     * Rapports : indicateurs de présence, heures et anomalies, graphiques et exports.
     */
    public function reports(Request $request): Response
    {
        [$period, $filters, $meta] = $this->scope($request);
        $today = Insights::today();
        $expected = $period->filter(fn (array $x): bool => $x[0]->date->lessThanOrEqualTo($today));
        $present = $expected->filter(fn (array $x): bool => $x[1]['arrivalValid'])->count();
        $rate = $expected->count() ? $present / $expected->count() * 100 : 0;
        $days = $period->map(fn (array $x): string => $x[0]->date->format('Y-m-d'))->unique()->sort()->values()->slice(-8);
        $absences = $period->filter(fn (array $x): bool => (bool) $x[0]->absence)->countBy(fn (array $x): string => $x[0]->absence['motif']);
        $anomalies = $period->flatMap(fn (array $x) => $x[0]->anomalies)->countBy(fn (AttendanceAnomaly $a): string => $a->type);

        return Inertia::render('Attendances/Reports', [
            ...$this->common('/pointages/rapports', $filters, $meta),
            'tiles' => [
                $this->tile('Jours attendus', $expected->count(), 'groups', 'info'),
                $this->tile('Jours travaillés (complets)', $period->filter(fn (array $x): bool => $x[1]['arrivalValid'] && $x[1]['departureValid'])->count(), 'event_available', 'success'),
                $this->tile('Taux de présence', round($rate).' %', 'percent', 'success', null, $rate),
                $this->tile('Heures nettes', Calc::duration((int) $period->sum(fn (array $x): int => $x[1]['net'] ?? 0)), 'timer', 'info'),
                $this->tile('Heures supplémentaires', Calc::duration((int) $period->sum(fn (array $x): int => $x[1]['overtime'])), 'more_time', 'purple'),
                $this->tile('Absences', $period->filter(fn (array $x): bool => (bool) $x[0]->absence)->count(), 'person_off', 'danger'),
                $this->tile('Retards', $period->filter(fn (array $x): bool => $x[1]['lateFlag'])->count(), 'schedule', 'orange'),
                $this->tile('Journées clôturées', $period->filter(fn (array $x): bool => $x[1]['closed'])->count(), 'lock', 'neutral', 'Données disponibles pour progression et paiement'),
            ],
            'charts' => [
                ['title' => 'Présence par jour', 'sub' => 'Arrivées validées / attendus', 'unit' => ' %', 'bars' => $days->map(function (string $d) use ($period): array {
                    $list = $period->filter(fn (array $x): bool => $x[0]->date->format('Y-m-d') === $d);

                    return [date('d/m', strtotime($d)), $list->count() ? (int) round($list->filter(fn (array $x): bool => $x[1]['arrivalValid'])->count() / $list->count() * 100) : 0];
                })->values()->all()],
                ['title' => 'Heures nettes par chantier', 'sub' => 'Période sélectionnée', 'unit' => ' h', 'bars' => $period->groupBy(fn (array $x): string => $x[0]->site->name)->map(fn (Collection $l, string $name): array => [$name, (int) round($l->sum(fn (array $x): int => $x[1]['net'] ?? 0) / 60)])->values()->all()],
                ['title' => 'Absences par motif', 'sub' => 'Déclarées par les promoteurs', 'unit' => '', 'accent' => true, 'bars' => $absences->map(fn (int $n, string $k): array => [$k, $n])->values()->all()],
                ['title' => 'Anomalies par type', 'sub' => 'Détectées et signalées', 'unit' => '', 'accent' => true, 'bars' => $anomalies->map(fn (int $n, string $k): array => [$k, $n])->values()->all()],
            ],
            'header' => $this->header('Rapports de pointage', 'Présences, heures travaillées et anomalies sur la période', [['Pointages', '/pointages'], ['Rapports']]),
        ]);
    }

    /**
     * Exports des sous-pages : incomplets, absences, rapport de présence ou heures par ouvrier (période et filtres actifs).
     */
    public function export(Request $request, string $view): StreamedResponse
    {
        [$period, $filters] = $this->scope($request);
        $items = Insights::filter($period, $filters, ['chantier', 'promoteur', 'metier', 'arrivee', 'depart']);

        if ($view === 'heures-ouvriers') {
            $rows = $items->groupBy(fn (array $x): string => $x[0]->worker->name)->map(fn (Collection $l, string $name): array => [
                $name, $l->filter(fn (array $x): bool => $x[1]['arrivalValid'] && $x[1]['departureValid'])->count(), Calc::duration((int) $l->sum(fn (array $x): int => $x[1]['net'] ?? 0)),
                Calc::duration((int) $l->sum(fn (array $x): int => $x[1]['overtime'])), $l->filter(fn (array $x): bool => (bool) $x[0]->absence)->count(),
            ]);

            return response()->streamDownload(function () use ($rows): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\u{FEFF}");
                fputcsv($out, ['Ouvrier', 'Jours complets', 'Heures nettes', 'Heures sup.', 'Absences'], ';');
                foreach ($rows as $r) {
                    fputcsv($out, $r, ';');
                }
                fclose($out);
            }, 'heures-ouvriers-'.Insights::today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $items = match ($view) {
            'incomplets' => $items->filter(fn (array $x): bool => (bool) $x[1]['incomplete']),
            'absences' => $items->filter(fn (array $x): bool => (bool) $x[0]->absence),
            default => $items,
        };

        return Insights::csv(['incomplets' => 'pointages-incomplets', 'absences' => 'absences'][$view] ?? 'rapport-presence', Insights::sort($items->values(), 'date'));
    }
}
