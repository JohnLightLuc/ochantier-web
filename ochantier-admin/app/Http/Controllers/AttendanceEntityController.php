<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Attendance;
use App\Models\Site;
use App\Models\Worker;
use App\Support\AttendanceCalculator as Calc;
use App\Support\AttendanceInsights as Insights;
use App\Support\AttendanceRecorder as Recorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pointages d’un chantier (journée, semaine, mois) ou d’un ouvrier (historique, affectations),
 * et simulation de l’Application Promoteur pour la journée en cours.
 */
class AttendanceEntityController extends Controller
{
    public const SITE_TABS = ['' => 'Journée', 'semaine' => 'Semaine', 'mois' => 'Mois'];

    public const WORKER_TABS = ['' => 'Historique', 'affectations' => 'Affectations'];

    /**
     * Indicateurs communs : présence, heures normales et supplémentaires, journées à traiter.
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @return array<int, array<string, string>>
     */
    private function kpis(Collection $items, bool $site): array
    {
        $past = $items->filter(fn (array $x): bool => $x[0]->date->lessThanOrEqualTo(Insights::today()));
        $present = $past->filter(fn (array $x): bool => $x[1]['arrivalValid'])->count();

        return [
            ['label' => $site ? 'Ouvriers suivis' : 'Jours attendus', 'value' => (string) ($site ? $items->map(fn (array $x): int => $x[0]->worker_id)->unique()->count() : $past->count()), 'icon' => 'groups', 'tone' => 'b', 'hint' => $site ? "{$past->count()} journée(s) attendue(s)" : 'Hors journées à venir'],
            ['label' => 'Taux de présence', 'value' => ($past->count() ? (int) round($present / $past->count() * 100) : 0).' %', 'icon' => 'percent', 'tone' => 'g', 'hint' => "{$present} présence(s) validée(s)"],
            ['label' => 'Heures normales', 'value' => Calc::duration((int) $items->sum(fn (array $x): int => min($x[1]['net'] ?? 0, $x[1]['plan']))), 'icon' => 'timer', 'tone' => 'b', 'hint' => 'Heures sup. '.Calc::duration((int) $items->sum(fn (array $x): int => $x[1]['overtime']))],
            ['label' => 'À traiter', 'value' => (string) $items->filter(fn (array $x): bool => $x[1]['incomplete'] || $x[1]['openAnomalies']->isNotEmpty())->count(), 'icon' => 'report', 'tone' => 'a', 'hint' => 'Incomplets et anomalies'],
        ];
    }

    private function tile(string $label, int|string $value, string $icon, string $tone, ?float $bar = null): array
    {
        return array_filter(['label' => $label, 'value' => (string) $value, 'icon' => $icon, 'tone' => $tone, 'bar' => $bar], fn ($v): bool => $v !== null);
    }

    public function site(Request $request, Site $site, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::SITE_TABS), 404);
        $all = Insights::load(scope: fn ($q) => $q->where('site_id', $site->id));
        abort_if($all->isEmpty(), 404, 'Aucune donnée de pointage.');
        $trade = (string) $request->query('metier', '');
        $items = $all->filter(fn (array $x): bool => $trade === '' || $x[0]->worker->trade === $trade)->values();
        $today = Insights::today();
        $dates = $all->map(fn (array $x): string => $x[0]->date->format('Y-m-d'))->unique()->sort()->values();
        $day = $dates->contains($request->query('date')) ? $request->query('date') : $today->format('Y-m-d');
        $base = "/pointages/chantiers/{$site->slug}";
        $data = [];

        if ($tab === '') {
            $list = $items->filter(fn (array $x): bool => $x[0]->date->format('Y-m-d') === $day)->values();
            $n = fn (callable $f): int => $list->filter($f)->count();
            $closed = $n(fn (array $x): bool => $x[1]['closed']);
            $data = [
                'day' => $day,
                'dates' => $dates->reverse()->values()->all(),
                'tiles' => [
                    $this->tile('Attendus', $list->count(), 'groups', 'info'),
                    $this->tile('Présents', $n(fn (array $x): bool => $x[1]['arrivalValid']), 'engineering', 'success'),
                    $this->tile('Départs validés', $n(fn (array $x): bool => $x[1]['departureValid']), 'task_alt', 'success'),
                    $this->tile('Absences', $n(fn (array $x): bool => (bool) $x[0]->absence), 'person_off', 'danger'),
                    $this->tile('Retards', $n(fn (array $x): bool => $x[1]['lateFlag']), 'schedule', 'orange'),
                    $this->tile('Incomplètes', $n(fn (array $x): bool => (bool) $x[1]['incomplete']), 'pending_actions', 'warning'),
                    $this->tile('Anomalies', $n(fn (array $x): bool => $x[1]['openAnomalies']->isNotEmpty()), 'report', 'danger'),
                    $this->tile('Heures travaillées', Calc::duration((int) $list->sum(fn (array $x): int => $x[1]['net'] ?? 0)), 'timer', 'info'),
                    $this->tile('Progression de clôture', ($list->count() ? (int) round($closed / $list->count() * 100) : 0).' %', 'lock', 'neutral', $list->count() ? $closed / $list->count() * 100 : 0),
                ],
                'rows' => Insights::rows($list),
            ];
        } elseif ($tab === 'semaine') {
            $week = $items->filter(fn (array $x): bool => $x[0]->date->greaterThan($today->subDays(7)) && $x[0]->date->lessThanOrEqualTo($today->addDay()))->values();
            $data = AttendanceSectionController::grid($week, 8, false);
        } else {
            $month = $items->filter(fn (array $x): bool => $x[0]->date->format('Y-m') === $today->format('Y-m'))->groupBy(fn (array $x): string => $x[0]->date->format('Y-m-d'))->sortKeysDesc();
            $data = ['monthLabel' => $today->locale('fr')->isoFormat('MMMM YYYY'), 'days' => $month->map(function (Collection $list, string $d): array {
                $n = fn (callable $f): int => $list->filter($f)->count();

                return [
                    'date' => $d, 'expected' => $list->count(), 'present' => $n(fn (array $x): bool => $x[1]['arrivalValid']), 'absent' => $n(fn (array $x): bool => (bool) $x[0]->absence),
                    'late' => $n(fn (array $x): bool => $x[1]['lateFlag']), 'incomplete' => $n(fn (array $x): bool => (bool) $x[1]['incomplete']), 'anomalies' => $n(fn (array $x): bool => $x[1]['openAnomalies']->isNotEmpty()),
                    'net' => Calc::duration((int) $list->sum(fn (array $x): int => $x[1]['net'] ?? 0)), 'closure' => $list->count() ? (int) round($n(fn (array $x): bool => $x[1]['closed']) / $list->count() * 100) : 0,
                ];
            })->values()->all()];
        }

        return Inertia::render('Attendances/Site', [
            'sections' => Insights::sections(''),
            'site' => ['name' => $site->name, 'slug' => $site->slug, 'status' => ['label' => $site->status->label(), 'tone' => $site->status->tone()], 'promoter' => $site->promoter_name, 'commune' => $site->commune, 'city' => $site->city, 'hours' => $site->working_hours, 'code' => $site->reference, 'manager' => $site->manager, 'blocked' => in_array($site->status, [SiteStatus::Suspended, SiteStatus::Closed], true)],
            'kpis' => $this->kpis($items, true),
            'trades' => $all->map(fn (array $x): string => $x[0]->worker->trade)->unique()->sort()->values()->all(),
            'trade' => $trade,
            'tab' => $tab,
            'tabs' => collect(self::SITE_TABS)->map(fn (string $l, string $k): array => [$k, $l])->values()->all(),
            'data' => $data,
            'options' => Insights::options(),
            'header' => $this->header('Pointages du chantier', '', [['Pointages', '/pointages'], [$site->name, $tab ? $base : null], ...($tab ? [[self::SITE_TABS[$tab]]] : [])]),
        ]);
    }

    public function worker(Worker $worker, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::WORKER_TABS), 404);
        $items = Insights::load(scope: fn ($q) => $q->where('worker_id', $worker->id));
        abort_if($items->isEmpty(), 404, 'Aucune donnée de pointage.');
        $past = $items->filter(fn (array $x): bool => $x[0]->date->lessThanOrEqualTo(Insights::today()));
        $n = fn (callable $f): int => $past->filter($f)->count();
        $base = "/pointages/ouvriers/{$worker->slug}";

        return Inertia::render('Attendances/Worker', [
            'sections' => Insights::sections(''),
            'worker' => ['name' => $worker->name, 'slug' => $worker->slug, 'code' => $worker->code, 'trade' => $worker->trade, 'commune' => $worker->commune, 'phone' => $worker->phone, 'status' => ['label' => $worker->status->label(), 'tone' => $worker->status->tone()], 'availability' => $worker->availability->label()],
            'kpis' => $this->kpis($items, false),
            'tab' => $tab,
            'tabs' => collect(self::WORKER_TABS)->map(fn (string $l, string $k): array => [$k, $l])->values()->all(),
            'tiles' => [
                $this->tile('Jours présents', $n(fn (array $x): bool => $x[1]['arrivalValid']), 'event_available', 'success'),
                $this->tile('Absences', $n(fn (array $x): bool => (bool) $x[0]->absence), 'person_off', 'danger'),
                $this->tile('Retards', $n(fn (array $x): bool => $x[1]['lateFlag']), 'schedule', 'orange'),
                $this->tile('Départs anticipés', $n(fn (array $x): bool => (bool) $x[1]['early']), 'logout', 'orange'),
                $this->tile('Heures supplémentaires', Calc::duration((int) $items->sum(fn (array $x): int => $x[1]['overtime'])), 'more_time', 'purple'),
                $this->tile('Pointages incomplets', $n(fn (array $x): bool => (bool) $x[1]['incomplete']), 'pending_actions', 'warning'),
                $this->tile('Anomalies', $n(fn (array $x): bool => $x[1]['openAnomalies']->isNotEmpty()), 'report', 'danger'),
            ],
            'rows' => Insights::rows(Insights::sort($items, 'date')),
            'assignments' => $items->groupBy(fn (array $x): string => $x[0]->assignment_reference)->map(function (Collection $list, string $ref): array {
                $sorted = $list->sortBy(fn (array $x) => $x[0]->date)->values();
                $p = $sorted->first()[0];

                return ['reference' => $ref, 'site' => $p->site->name, 'siteSlug' => $p->site->slug, 'promoter' => $p->site->promoter_name, 'hours' => "{$p->planned_start} – {$p->planned_end}", 'first' => $p->date->format('Y-m-d'), 'last' => $sorted->last()[0]->date->format('Y-m-d'), 'days' => $sorted->count()];
            })->values()->all(),
            'options' => Insights::options(),
            'header' => $this->header('Pointages de l’ouvrier', '', [['Pointages', '/pointages'], [$worker->name, $tab ? $base : null], ...($tab ? [[self::WORKER_TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Simulation de l’Application Promoteur : ouvriers attendus aujourd’hui sur un chantier, regroupés par étape.
     */
    public function app(Request $request): Response
    {
        $today = Insights::today();
        $sites = Site::query()->whereIn('id', Attendance::query()->select('site_id'))->orWhere('status', SiteStatus::Suspended)->orderBy('name')->get();
        $site = $sites->firstWhere('slug', $request->query('chantier')) ?? $sites->first();
        $items = $site ? Insights::load($today, $today, fn ($q) => $q->where('site_id', $site->id)) : collect();
        $groups = [
            ['Ouvriers attendus', 'groups', fn (array $x): bool => ! $x[0]->arrival['time'] && ! $x[0]->absence, ['arrivee', 'absence']],
            ['Arrivées à valider', 'pending', fn (array $x): bool => $x[1]['arrivalPending'], ['validation-arrivee']],
            ['Ouvriers présents', 'engineering', fn (array $x): bool => $x[1]['arrivalValid'] && ! $x[0]->departure['time'], ['depart']],
            ['Départs à valider', 'pending', fn (array $x): bool => $x[1]['departurePending'], ['validation-depart']],
            ['Absences à valider', 'person_off', fn (array $x): bool => ($x[0]->absence['status'] ?? null) === 'En attente de validation', ['validation-absence']],
            ['Journées terminées', 'event_available', fn (array $x): bool => $x[1]['departureValid'] || ($x[0]->absence && $x[0]->absence['status'] !== 'En attente de validation'), []],
        ];

        return Inertia::render('Attendances/App', [
            'sections' => Insights::sections('/pointages/application'),
            'sites' => $sites->map(fn (Site $s): array => ['value' => $s->slug, 'label' => "{$s->name} · {$s->promoter_name}".(in_array($s->status, [SiteStatus::Suspended, SiteStatus::Closed], true) ? ' ('.mb_strtolower($s->status->label()).')' : '')])->all(),
            'site' => $site ? ['slug' => $site->slug, 'name' => $site->name, 'promoter' => $site->promoter_name, 'manager' => $site->manager, 'status' => $site->status->label(), 'blocked' => in_array($site->status, [SiteStatus::Suspended, SiteStatus::Closed, SiteStatus::Cancelled], true), 'commune' => $site->commune] : null,
            'today' => $today->format('Y-m-d'),
            'groups' => collect($groups)->map(fn (array $g): array => [
                'title' => $g[0], 'icon' => $g[1], 'actions' => $g[3],
                'rows' => $items->filter($g[2])->map(fn (array $x): array => [
                    ...Insights::row($x[0], $x[1]),
                    'recap' => $this->recap($x[0], $x[1]),
                    'serverTimes' => ['arrival' => Recorder::serverTime($x[0], 'arrival'), 'departure' => Recorder::serverTime($x[0], 'departure')],
                    'absence' => $x[0]->absence,
                ])->values()->all(),
            ])->all(),
            'labels' => Recorder::ACTIONS,
            'absenceMotifs' => Recorder::ABSENCE_MOTIFS,
            'header' => $this->header('Application Promoteur · pointage', $site ? "{$site->name} · {$site->promoter_name} · {$site->status->label()}" : '', [['Pointages', '/pointages'], ['Application Promoteur']]),
        ]);
    }

    /**
     * Récapitulatif présenté au promoteur avant une validation.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function recap(Attendance $p, array $c): array
    {
        $cfg = Calc::rules();
        $a = $p->arrival;
        $d = $p->departure;
        $late = $a['time'] ? Calc::minutes($a['time']) - Calc::minutes($p->planned_start) : 0;
        $gross = $a['time'] && $d['time'] ? (Calc::minutes($d['time']) - Calc::minutes($a['time']) + 1440) % 1440 : null;
        $pause = (int) collect($p->pauses)->sum('minutes');
        $early = $d['time'] ? (Calc::minutes($p->planned_end) - Calc::minutes($d['time']) + 1440) % 1440 : 0;

        return [
            'validation-arrivee' => [
                ['Ouvrier', "{$p->worker->name} · {$p->worker->code}"], ['Métier', $p->worker->trade], ['Chantier', $p->site->name], ['Affectation', $p->assignment_reference],
                ['Date', $p->date->format('d/m/Y')], ['Heure prévue', $p->planned_start], ['Heure enregistrée (serveur)', $a['time'] ?? '—'],
                ['Retard', $late > $cfg['late_tolerance'] ? Calc::duration($late).' (au-delà de la tolérance)' : 'Dans la tolérance'],
                ['Localisation', $a['location'] ? "{$a['location']['status']} · {$a['location']['meters']} m" : 'Indisponible'],
                ['Preuve', collect($a['proofs'])->pluck('type')->implode(', ') ?: 'Aucune'], ['Commentaire', $a['comment'] ?? '—'],
            ],
            'validation-depart' => [
                ['Ouvrier', $p->worker->name], ['Chantier', $p->site->name], ['Arrivée validée', $a['time'] ?? '—'], ['Départ prévu', $p->planned_end], ['Départ enregistré (serveur)', $d['time'] ?? '—'],
                ['Durée brute', Calc::duration($gross)], ['Pauses', Calc::duration($pause)], ['Durée nette', Calc::duration($gross === null ? null : $gross - $pause)],
                ['Départ anticipé', $early > $cfg['early_tolerance'] && $early < 600 ? 'Oui' : 'Non'],
                ['Heures supplémentaires', $gross !== null && $gross - $pause - $c['plan'] > $cfg['overtime_threshold'] ? Calc::duration($gross - $pause - $c['plan']) : 'Aucune'],
                ['Commentaire', $d['comment'] ?? '—'],
            ],
            'validation-absence' => $p->absence ? [['Ouvrier', $p->worker->name], ['Motif', $p->absence['motif']], ['Commentaire', $p->absence['comment'] ?? '—'], ['Justificatif', $p->absence['proof'] ?? 'Aucun']] : [],
        ];
    }

    /**
     * Action de l’Application Promoteur sur un ouvrier.
     */
    public function record(Request $request, Attendance $attendance, string $action): RedirectResponse
    {
        $attendance->load('worker', 'site', 'anomalies', 'corrections');
        $error = Recorder::check($attendance, $action);
        abort_if($error !== '', 422, $error);
        $options = match ($action) {
            'arrivee', 'depart' => $request->validate([
                'zone' => ['required', Rule::in(['dans', 'hors'])],
                'photo' => ['required', 'boolean'],
                'signature' => ['required', 'boolean'],
                'comment' => ['required', 'string', 'min:3', 'max:500'],
            ], ['comment.*' => 'La note (description) est obligatoire.']),
            'absence' => $request->validate(['motif' => ['required', Rule::in(Recorder::ABSENCE_MOTIFS)], 'proof' => ['nullable', 'string', 'max:120'], 'comment' => ['nullable', 'string', 'max:500']]),
            default => [],
        };
        $time = Recorder::record($attendance, $action, [...$options, 'outside' => ($options['zone'] ?? '') === 'hors', 'photo' => (bool) ($options['photo'] ?? true), 'signature' => (bool) ($options['signature'] ?? true)]);
        $label = str_replace(['Pointer', 'Valider'], ['Pointage :', 'Validé :'], Recorder::ACTIONS[$action]);
        Inertia::flash('toast', "{$label} {$attendance->worker->name}".($time ? " à {$time}" : '')." · par {$attendance->promoterLabel()} · prochaine étape : ".Recorder::NEXT[$action].'.');

        return back();
    }

    /**
     * Pointage groupé : un enregistrement distinct par ouvrier ; une erreur n’annule pas les opérations réussies.
     */
    public function recordMany(Request $request): RedirectResponse
    {
        $data = $request->validate(['refs' => ['required', 'array', 'min:1'], 'refs.*' => ['string'], 'action' => ['required', Rule::in(['arrivee', 'validation-arrivee', 'depart', 'validation-depart'])]]);
        $results = Attendance::with('worker', 'site', 'anomalies', 'corrections')->whereIn('reference', $data['refs'])->get()->map(function (Attendance $p) use ($data): array {
            $error = Recorder::check($p, $data['action']);
            if ($error !== '') {
                return ['name' => $p->worker->name, 'detail' => $error, 'result' => preg_match('/déjà|existe/', $error) ? 'Ignoré' : 'Échoué'];
            }
            Recorder::record($p, $data['action']);

            return ['name' => $p->worker->name, 'detail' => 'Effectué', 'result' => Calc::calculate($p->load('anomalies'))['openAnomalies']->isNotEmpty() ? 'À vérifier' : 'Réussi'];
        });
        $count = fn (string $r): int => $results->where('result', $r)->count();
        Inertia::flash('groupResult', [
            'title' => Recorder::ACTIONS[$data['action']].' · groupé',
            'summary' => "{$count('Réussi')} réussi(s) · {$count('Échoué')} échoué(s) · {$count('Ignoré')} ignoré(s) · {$count('À vérifier')} à vérifier",
            'items' => $results->sortBy(fn (array $r): int => array_search($r['result'], ['Réussi', 'À vérifier', 'Ignoré', 'Échoué'], true))->values()->all(),
        ]);

        return back();
    }
}
