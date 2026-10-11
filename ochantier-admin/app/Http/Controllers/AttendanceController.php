<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceActivity;
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
 * Pointages : supervision des arrivées et départs enregistrés puis validés par les promoteurs
 * depuis l’Application Promoteur (liste, fiche d’une journée).
 */
class AttendanceController extends Controller
{
    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    public const TABS = ['' => 'Vue d’ensemble', 'arrivee' => 'Arrivée', 'depart' => 'Départ', 'temps' => 'Temps de présence', 'preuves' => 'Preuves', 'anomalies' => 'Anomalies', 'corrections' => 'Corrections', 'impact-financier' => 'Impact financier', 'historique' => 'Historique'];

    public const ANOMALY_TYPES = [
        'Pointage hors zone', 'Pointage en double', 'Départ sans arrivée', 'Départ antérieur à l’arrivée', 'Durée anormalement courte', 'Durée anormalement longue', 'Chevauchement de deux chantiers',
        'Même preuve utilisée plusieurs fois', 'Modification répétée', 'Pointage sur affectation inactive', 'Pointage pendant une indisponibilité', 'Localisation incohérente',
        'Heure du terminal différente de l’heure serveur', 'Preuve illisible', 'Ouvrier déjà déclaré absent', 'Promoteur non autorisé sur le chantier',
    ];

    public const LEVELS = ['Information', 'Faible', 'Modérée', 'Élevée', 'Critique'];

    public const ANOMALY_STATUSES = ['Nouvelle', 'À vérifier', 'En cours d’analyse', 'Justification demandée', 'Confirmée', 'Fausse alerte', 'Corrigée', 'Clôturée'];

    public const CORRECTION_FIELDS = ['Heure d’arrivée', 'Heure de départ', 'Durée de pause', 'Localisation', 'Motif d’absence'];

    public const CORRECTION_MOTIFS = ['Oubli de pointage', 'Erreur de saisie', 'Problème technique', 'Heure réelle différente', 'Autre'];

    /**
     * Décisions possibles sur un retard, un départ anticipé, des heures supplémentaires ou une absence.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    public const DECISIONS = [
        'lateness' => ['Retard', ['Justifié', 'Non justifié', 'Contesté', 'Régularisé']],
        'early_leave' => ['Départ anticipé', ['Justifié', 'Non justifié', 'Autorisé', 'Contesté', 'Régularisé']],
        'overtime' => ['Heures supplémentaires', ['Autorisées', 'Refusées', 'Validées']],
        'absence' => ['Absence', ['Validée', 'Justifiée', 'Non justifiée', 'Contestée', 'Annulée', 'Régularisée']],
    ];

    /**
     * Filtres lus dans l’URL.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only(['periode', 'du', 'au', 'q', 'raccourci', 'tri', 'page', 'plus', ...array_keys(Insights::FILTERS)]);
    }

    /**
     * Pointages de la période, recherchés, filtrés, triés (et restreints au raccourci actif).
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Collection<int, array{0: Attendance, 1: array<string, mixed>}>, 1: Collection<int, array{0: Attendance, 1: array<string, mixed>}>, 2: array<string, mixed>}
     */
    private function listed(array $filters, string $defaultPeriod): array
    {
        $key = array_key_exists($filters['periode'] ?? '', Insights::PERIODS) ? $filters['periode'] : $defaultPeriod;
        [$from, $to] = Insights::period($key, $filters['du'] ?? null, $filters['au'] ?? null);
        $period = Insights::load($from, $to);
        $shortcut = array_key_exists($filters['raccourci'] ?? '', Insights::SHORTCUTS) ? $filters['raccourci'] : null;
        $rows = Insights::sort(Insights::filter($period, $filters), $filters['tri'] ?? 'date')
            ->filter(fn (array $x): bool => ! $shortcut || Insights::matchesShortcut($shortcut, $x[0], $x[1]))->values();

        return [$period, $rows, ['key' => $key, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $from->equalTo($to) ? Insights::dateLabel($from) : Insights::dateLabel($from).' → '.Insights::dateLabel($to), 'shortcut' => $shortcut]];
    }

    /**
     * Liste des pointages (ou ceux du jour) : raccourcis, filtres, tri, sélection et export.
     */
    public function index(Request $request): Response
    {
        $today = $request->routeIs('attendances.today');
        $filters = $this->filters($request);
        if ($today) {
            $filters['periode'] = 'jour';
        }
        [$period, $rows, $meta] = $this->listed($filters, 'jour');
        $count = fn (string $k): int => $period->filter(fn (array $x): bool => Insights::matchesShortcut($k, $x[0], $x[1]))->count();
        $more = (bool) ($filters['plus'] ?? false);

        return Inertia::render('Attendances/Index', [
            'sections' => Insights::sections($today ? '/pointages/aujourdhui' : '/pointages'),
            'period' => $meta,
            'periods' => $today ? [] : Insights::PERIODS,
            'filters' => array_filter($filters),
            'shortcuts' => collect(Insights::SHORTCUTS)->map(fn (array $s, string $k): array => ['key' => $k, 'label' => $s[0], 'icon' => $s[1], 'tone' => $s[2], 'count' => $count($k), 'kpi' => in_array($k, Insights::KPI_SHORTCUTS, true)])->values()->all(),
            'filterDefs' => Insights::filterOptions($period, $more ? array_keys(Insights::FILTERS) : Insights::BASIC_FILTERS),
            'filterCount' => count(Insights::FILTERS),
            'sorts' => Insights::SORTS,
            'attendances' => Insights::paginate($rows, (int) ($filters['page'] ?? 1), 8, fn (array $x): array => Insights::row($x[0], $x[1])),
            'hasData' => Attendance::query()->exists(),
            'options' => Insights::options(),
            'header' => $today ? $this->header('Pointages du jour', '', [['Pointages', '/pointages'], ['Aujourd’hui']]) : $this->header('Gestion des pointages', 'Enregistrés et validés par les promoteurs depuis l’Application Promoteur · supervision et contrôle'),
        ]);
    }

    /**
     * Export CSV de la liste (filtres actifs) ou d’une sélection (?refs=PT-…,PT-…).
     */
    public function export(Request $request): StreamedResponse
    {
        $refs = array_filter(explode(',', (string) $request->query('refs', '')));
        [, $rows] = $this->listed($this->filters($request), 'jour');
        if ($refs) {
            $rows = Insights::load(scope: fn ($q) => $q->whereIn('reference', $refs));
        }

        return Insights::csv($refs ? 'pointages-selection' : 'pointages', $rows);
    }

    /**
     * Fiche d’une journée de pointage.
     */
    public function show(Attendance $attendance, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $p = $attendance->load([...Insights::RELATIONS, 'site.promoter', 'activities']);
        $c = Calc::calculate($p);
        $cfg = Calc::rules();
        $base = "/pointages/{$p->reference}";
        $firstOpen = $c['openAnomalies']->first();
        $absence = $p->absence;

        return Inertia::render('Attendances/Show', [
            'attendance' => [
                ...Insights::row($p, $c),
                'site' => ['name' => $p->site->name, 'slug' => $p->site->slug, 'promoter' => $p->site->promoter_name, 'promoterSlug' => $p->site->promoter?->slug, 'commune' => $p->site->commune, 'latitude' => $p->site->latitude, 'longitude' => $p->site->longitude],
                'arrivalEvent' => $p->arrival,
                'departureEvent' => $p->departure,
                'pauses' => $p->pauses,
                'absence' => $absence,
                'decisions' => collect(self::DECISIONS)->filter(fn (array $d, string $key): bool => (bool) $p->{$key})->map(fn (array $d, string $key): array => ['key' => $key, 'label' => $d[0], 'status' => $p->{$key}['status'], 'options' => $d[1]])->values()->all(),
                'closure' => $p->closure,
                'transmission' => $p->transmission,
                'notes' => $p->notes,
                'tag' => $firstOpen ? ['label' => $firstOpen->type, 'tone' => Calc::tone($firstOpen->level)] : ['label' => $c['indicators'][0] ?? $c['closureStatus'], 'tone' => 'n'],
            ],
            'calc' => [
                ...collect($c)->except(['openAnomalies', 'blocking', 'pendingCorrections'])->all(),
                'openAnomalies' => $c['openAnomalies']->map(fn (AttendanceAnomaly $a): array => $a->only(['id', 'type', 'level', 'note']))->values()->all(),
                'pendingCorrections' => $c['pendingCorrections']->map(fn (AttendanceCorrection $x): array => $x->only(['id', 'field', 'old_value', 'new_value']))->values()->all(),
                'labels' => [
                    'net' => Calc::duration($c['net']), 'gross' => Calc::duration($c['gross']), 'pause' => Calc::duration($c['pause']), 'plan' => Calc::duration($c['plan']),
                    'late' => $c['lateFlag'] ? Calc::duration($c['late']) : null, 'early' => $c['early'] ? Calc::duration($c['early']) : null, 'overtime' => $c['overtime'] ? Calc::duration($c['overtime']) : null,
                    'unpaid' => $c['net'] !== null && $c['net'] < $c['plan'] ? Calc::duration($c['plan'] - $c['net']) : null,
                    'min' => Calc::duration($cfg['min_duration']),
                ],
            ],
            'anomalies' => $p->anomalies->map(fn (AttendanceAnomaly $a): array => [...$a->only(['id', 'type', 'level', 'status', 'note', 'decision']), 'detectedOn' => $a->detected_on->format('Y-m-d')])->values()->all(),
            'corrections' => $p->corrections->map(fn (AttendanceCorrection $x): array => [
                ...$x->only(['id', 'field', 'motif', 'comment', 'proof', 'status', 'decision', 'impact']),
                'oldValue' => $x->old_value, 'newValue' => $x->new_value, 'requestedBy' => $x->requested_by, 'requestedAt' => $x->requested_at->format('Y-m-d H:i'),
            ])->values()->all(),
            'activities' => $p->activities->map(fn (AttendanceActivity $e): array => [
                'id' => $e->id, 'date' => $e->occurred_at->format('Y-m-d'), 'time' => $e->occurred_at->format('H:i'), 'author' => $e->author,
                'category' => ['value' => $e->category, 'label' => ucfirst($e->category), 'tone' => self::categoryTone($e->category), 'icon' => self::categoryIcon($e->category)],
                'action' => $e->action, 'oldValue' => $e->old_value, 'newValue' => $e->new_value, 'comment' => $e->comment, 'origin' => $e->origin,
            ])->values()->all(),
            'correctionValues' => $this->correctionValues($p),
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn (string $label, string $key): array => [$key, $label, match ($key) {
                'anomalies' => $c['openAnomalies']->count(),
                'corrections' => $c['pendingCorrections']->count(),
                default => 0,
            }])->values()->all(),
            'rules' => $cfg,
            'options' => Insights::options(),
            'header' => $this->header('Fiche du pointage', '', [['Pointages', '/pointages'], [$p->reference, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Valeurs actuelles des données corrigeables.
     *
     * @return array<string, string>
     */
    private function correctionValues(Attendance $p): array
    {
        return [
            'Heure d’arrivée' => $p->arrival['time'] ?? '—',
            'Heure de départ' => $p->departure['time'] ?? '—',
            'Durée de pause' => isset($p->pauses[0]) ? $p->pauses[0]['minutes'].' min' : '—',
            'Localisation' => $p->arrival['location'] ? $p->arrival['location']['meters'].' m' : '—',
            'Motif d’absence' => $p->absence['motif'] ?? '—',
        ];
    }

    public static function categoryTone(string $category): string
    {
        return match ($category) {
            'validation', 'pointage' => 'success',
            'statut' => 'info',
            'modification' => 'purple',
            'rejet', 'litige' => 'danger',
            'paiement' => 'orange',
            default => 'neutral',
        };
    }

    public static function categoryIcon(string $category): string
    {
        return match ($category) {
            'pointage' => 'schedule',
            'validation' => 'verified',
            'statut' => 'swap_vert',
            'modification' => 'edit',
            'rejet' => 'block',
            'litige' => 'report',
            'paiement' => 'payments',
            default => 'history',
        };
    }
}
