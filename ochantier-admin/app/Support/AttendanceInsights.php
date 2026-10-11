<?php

namespace App\Support;

use App\Http\Controllers\AttendanceController;
use App\Models\Attendance;
use App\Support\AttendanceCalculator as Calc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Présentation du module Pointages : périodes, raccourcis, filtres, lignes de tableau et exports.
 *
 * Chaque pointage est accompagné de son calcul (AttendanceCalculator) sous la forme [pointage, calcul].
 */
class AttendanceInsights
{
    /**
     * Relations à charger pour tous les calculs.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['worker', 'site', 'anomalies', 'corrections'];

    /**
     * Périodes proposées : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const PERIODS = ['jour' => 'Aujourd’hui', 'hier' => 'Hier', 'semaine' => 'Cette semaine', 'mois' => 'Ce mois', 'perso' => 'Période personnalisée'];

    /**
     * Raccourcis de la liste : clé → libellé, icône, ton ; les quatre premiers sont des cartes KPI.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const SHORTCUTS = [
        'att' => ['Ouvriers attendus', 'groups', 'b'], 'comp' => ['Présences complètes', 'event_available', 'g'], 'inc' => ['Pointages incomplets', 'pending_actions', 'a'], 'anom' => ['Anomalies à traiter', 'report', 'r'],
        'arrE' => ['Arrivées enregistrées', 'login', 'b'], 'arrV' => ['Arrivées validées', 'how_to_reg', 'g'], 'depE' => ['Départs enregistrés', 'logout', 'b'], 'depV' => ['Départs validés', 'task_alt', 'g'],
        'abs' => ['Absences', 'person_off', 'r'], 'late' => ['Retards', 'schedule', 'o'],
    ];

    public const KPI_SHORTCUTS = ['att', 'comp', 'inc', 'anom'];

    /**
     * Filtres : clé d’URL → libellé (les cinq premiers sont visibles sans « Plus de filtres »).
     *
     * @var array<string, string>
     */
    public const FILTERS = [
        'chantier' => 'Chantier', 'promoteur' => 'Promoteur', 'metier' => 'Métier', 'journee' => 'Statut de journée', 'anomalie' => 'Anomalie',
        'ouvrier' => 'Ouvrier', 'commune' => 'Commune', 'arrivee' => 'Statut d’arrivée', 'depart' => 'Statut de départ', 'presence' => 'Présence', 'retard' => 'Retard',
        'anticipe' => 'Départ anticipé', 'hs' => 'Heures supplémentaires', 'correction' => 'Correction demandée', 'mode' => 'Mode d’enregistrement', 'responsable' => 'Responsable administratif', 'cloture' => 'Clôture',
    ];

    public const BASIC_FILTERS = ['chantier', 'promoteur', 'metier', 'journee', 'anomalie'];

    /**
     * Tris : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const SORTS = ['date' => 'Date', 'arrivee' => 'Heure d’arrivée', 'depart' => 'Heure de départ', 'duree' => 'Durée de présence', 'retard' => 'Retard', 'hs' => 'Heures supplémentaires', 'chantier' => 'Chantier', 'maj' => 'Dernière mise à jour'];

    /**
     * Sections du module (barre de navigation) : chemin → libellé, icône.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SECTIONS = [
        '/pointages' => ['Tous les pointages', 'list'], '/pointages/aujourdhui' => ['Aujourd’hui', 'today'], '/pointages/incomplets' => ['Incomplets', 'pending_actions'],
        '/pointages/absences' => ['Absences', 'person_off'], '/pointages/calendrier' => ['Calendrier', 'calendar_month'], '/pointages/anomalies' => ['Anomalies', 'report'],
        '/pointages/corrections' => ['Corrections', 'edit_note'], '/pointages/rapports' => ['Rapports', 'bar_chart'], '/pointages/application' => ['Application Promoteur', 'smartphone'],
    ];

    public static function today(): CarbonImmutable
    {
        return Attendance::referenceDate();
    }

    /**
     * Bornes de la période choisie (lundi → dimanche pour la semaine, 1er du mois → aujourd’hui pour le mois).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(string $key, ?string $from = null, ?string $to = null): array
    {
        $t = self::today();

        return match ($key) {
            'hier' => (fn (CarbonImmutable $d): array => [$d, $d])($t->subDay()->isSunday() ? $t->subDays(2) : $t->subDay()),
            'semaine' => [$t->startOfWeek(), $t->endOfWeek()->startOfDay()],
            'mois' => [$t->startOfMonth(), $t],
            'perso' => [self::date($from) ?? $t->subDays(6), self::date($to) ?? $t],
            default => [$t, $t],
        };
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::parse($value) : null;
    }

    public static function dateLabel(CarbonImmutable $d): string
    {
        return $d->format('d/m/Y');
    }

    /**
     * Pointages (avec calcul) sur une période.
     *
     * @return Collection<int, array{0: Attendance, 1: array<string, mixed>}>
     */
    public static function load(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?callable $scope = null): Collection
    {
        return Attendance::with(self::RELATIONS)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->when($scope, $scope)
            ->orderBy('id')->get()
            ->map(fn (Attendance $p): array => [$p, Calc::calculate($p)]);
    }

    /**
     * Le pointage relève-t-il du raccourci ?
     *
     * @param  array<string, mixed>  $c
     */
    public static function matchesShortcut(string $key, Attendance $p, array $c): bool
    {
        return match ($key) {
            'att' => $c['status'] !== 'Annulée',
            'arrE' => (bool) $p->arrival['time'],
            'arrV' => $c['arrivalValid'],
            'depE' => (bool) $p->departure['time'],
            'depV' => $c['departureValid'],
            'comp' => $c['arrivalValid'] && $c['departureValid'],
            'inc' => (bool) $c['incomplete'],
            'abs' => (bool) $p->absence,
            'late' => $c['lateFlag'],
            'anom' => $c['openAnomalies']->isNotEmpty(),
            default => true,
        };
    }

    /**
     * Valeur d’un pointage pour un filtre.
     *
     * @param  array<string, mixed>  $c
     */
    public static function filterValue(string $key, Attendance $p, array $c): ?string
    {
        return match ($key) {
            'chantier' => $p->site->name,
            'promoteur' => $p->site->promoter_name,
            'ouvrier' => $p->worker->name,
            'metier' => $p->worker->trade,
            'commune' => $p->site->commune,
            'arrivee' => $p->arrival['status'],
            'depart' => $p->departure['status'],
            'journee' => $c['status'],
            'presence' => $c['arrivalValid'] ? 'Présent' : ($p->absence ? 'Absent' : 'Non présent'),
            'retard' => $c['lateFlag'] ? 'Avec retard' : 'Sans retard',
            'anticipe' => $c['early'] ? 'Oui' : 'Non',
            'hs' => $c['overtime'] ? 'Oui' : 'Non',
            'anomalie' => $c['openAnomalies']->isNotEmpty() ? 'Avec anomalie' : 'Sans anomalie',
            'correction' => $c['pendingCorrections']->isNotEmpty() ? 'Oui' : 'Non',
            'mode' => $p->arrival['time'] ? ($p->arrival['proofs'] ? 'Application · avec photo' : 'Application · sans photo') : 'Aucun',
            'responsable' => ['N. Yao', 'A. Koné', 'M. Diabaté'][crc32($p->site->slug) % 3],
            'cloture' => $c['closureStatus'],
            default => null,
        };
    }

    /**
     * Applique la recherche et les filtres actifs.
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $keys
     * @return Collection<int, array{0: Attendance, 1: array<string, mixed>}>
     */
    public static function filter(Collection $items, array $filters, array $keys = []): Collection
    {
        $q = Str::lower(trim((string) ($filters['q'] ?? '')));
        $q = preg_replace('/^\+?225\s?/', '', $q);

        return $items->filter(function (array $x) use ($filters, $keys, $q): bool {
            [$p, $c] = $x;
            if ($q !== '' && ! str_contains(Str::lower(implode(' ', [$p->worker->name, $p->worker->code, str_replace(' ', '', (string) $p->worker->phone), $p->worker->phone, $p->site->name, $p->site->promoter_name, $p->assignment_reference, $p->reference])), $q)) {
                return false;
            }
            foreach ($keys ?: array_keys(self::FILTERS) as $key) {
                if (filled($filters[$key] ?? null) && self::filterValue($key, $p, $c) !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Options des filtres (valeurs présentes sur la période).
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @param  array<int, string>  $keys
     * @return array<int, array{key: string, label: string, options: array<int, string>}>
     */
    public static function filterOptions(Collection $items, array $keys): array
    {
        return collect($keys)->map(fn (string $key): array => [
            'key' => $key,
            'label' => self::FILTERS[$key],
            'options' => $items->map(fn (array $x): ?string => self::filterValue($key, $x[0], $x[1]))->filter()->unique()->sort(fn ($a, $b) => strcoll((string) $a, (string) $b))->values()->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @return Collection<int, array{0: Attendance, 1: array<string, mixed>}>
     */
    public static function sort(Collection $items, string $key): Collection
    {
        $time = fn (?string $t): string => $t ?? '99';

        return $items->sort(match ($key) {
            'arrivee' => fn ($a, $b) => strcmp($time($a[0]->arrival['time']), $time($b[0]->arrival['time'])),
            'depart' => fn ($a, $b) => strcmp($time($a[0]->departure['time']), $time($b[0]->departure['time'])),
            'duree' => fn ($a, $b) => ($b[1]['net'] ?? 0) <=> ($a[1]['net'] ?? 0),
            'retard' => fn ($a, $b) => ($b[1]['lateFlag'] ? $b[1]['late'] : 0) <=> ($a[1]['lateFlag'] ? $a[1]['late'] : 0),
            'hs' => fn ($a, $b) => $b[1]['overtime'] <=> $a[1]['overtime'],
            'chantier' => fn ($a, $b) => strcoll($a[0]->site->name, $b[0]->site->name),
            'maj' => fn ($a, $b) => $b[0]->last_update <=> $a[0]->last_update,
            default => fn ($a, $b) => $b[0]->date <=> $a[0]->date ?: strcoll($a[0]->worker->name, $b[0]->worker->name),
        })->values();
    }

    /**
     * Ligne de tableau d’un pointage.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function row(Attendance $p, array $c): array
    {
        return [
            'reference' => $p->reference,
            'date' => $p->date->format('Y-m-d'),
            'night' => $p->night,
            'worker' => ['name' => $p->worker->name, 'code' => $p->worker->code, 'trade' => $p->worker->trade, 'slug' => $p->worker->slug],
            'site' => ['name' => $p->site->name, 'slug' => $p->site->slug, 'promoter' => $p->site->promoter_name],
            'assignment' => $p->assignment_reference,
            'plannedStart' => $p->planned_start,
            'plannedEnd' => $p->planned_end,
            'arrival' => ['time' => $p->arrival['time'], 'status' => $p->arrival['status']],
            'departure' => ['time' => $p->departure['time'], 'status' => $p->departure['status']],
            'net' => Calc::duration($c['net']),
            'hasNet' => $c['net'] !== null,
            'late' => $c['lateFlag'] ? '+'.Calc::duration($c['late']) : null,
            'overtime' => $c['overtime'] ? '+'.Calc::duration($c['overtime']) : null,
            'status' => Calc::badge($c['status']),
            'indicators' => $c['indicators'],
            'incomplete' => $c['incomplete'],
            'closable' => $c['closable'],
            'closed' => $c['closed'],
            'blockers' => $c['blockers'],
        ];
    }

    /**
     * Rows of a collection for the frontend.
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $items): array
    {
        return $items->map(fn (array $x): array => self::row($x[0], $x[1]))->values()->all();
    }

    /**
     * Pagination simple (8 lignes par page, comme la maquette).
     *
     * @param  Collection<int, mixed>  $items
     * @return array{data: array<int, mixed>, page: int, lastPage: int, total: int, from: int, to: int}
     */
    public static function paginate(Collection $items, int $page, int $size = 8, ?callable $map = null): array
    {
        $last = max(1, (int) ceil($items->count() / $size));
        $page = min(max(1, $page), $last);
        $slice = $items->forPage($page, $size)->values();

        return [
            'data' => $map ? $slice->map($map)->all() : $slice->all(),
            'page' => $page,
            'lastPage' => $last,
            'total' => $items->count(),
            'from' => $items->isEmpty() ? 0 : ($page - 1) * $size + 1,
            'to' => min($items->count(), $page * $size),
        ];
    }

    /**
     * Export CSV des pointages (filtres actifs appliqués).
     *
     * @param  Collection<int, array{0: Attendance, 1: array<string, mixed>}>  $items
     */
    public static function csv(string $name, Collection $items): StreamedResponse
    {
        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Référence', 'Date', 'Ouvrier', 'Identifiant', 'Métier', 'Chantier', 'Promoteur', 'Affectation', 'Arrivée prévue', 'Arrivée', 'Statut arrivée', 'Départ prévu', 'Départ', 'Statut départ', 'Durée nette', 'Retard (min)', 'HS (min)', 'Statut journée'], ';');
            foreach ($items as [$p, $c]) {
                fputcsv($out, [$p->reference, $p->date->format('d/m/Y'), $p->worker->name, $p->worker->code, $p->worker->trade, $p->site->name, $p->site->promoter_name, $p->assignment_reference, $p->planned_start, $p->arrival['time'], $p->arrival['status'], $p->planned_end, $p->departure['time'], $p->departure['status'], Calc::duration($c['net']), $c['lateFlag'] ? $c['late'] : 0, $c['overtime'], $c['status']], ';');
            }
            fclose($out);
        }, "{$name}-".self::today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Listes des fenêtres de saisie (corrections, anomalies).
     *
     * @return array<string, array<int, string>>
     */
    public static function options(): array
    {
        return [
            'anomalyTypes' => AttendanceController::ANOMALY_TYPES, 'levels' => AttendanceController::LEVELS, 'anomalyStatuses' => AttendanceController::ANOMALY_STATUSES,
            'correctionFields' => AttendanceController::CORRECTION_FIELDS, 'correctionMotifs' => AttendanceController::CORRECTION_MOTIFS,
        ];
    }

    /**
     * Barre de navigation du module, avec la section courante.
     *
     * @return array<int, array{href: string, label: string, icon: string, active: bool}>
     */
    public static function sections(string $current): array
    {
        return collect(self::SECTIONS)->map(fn (array $s, string $href): array => ['href' => $href, 'label' => $s[0], 'icon' => $s[1], 'active' => $href === $current])->values()->all();
    }
}
