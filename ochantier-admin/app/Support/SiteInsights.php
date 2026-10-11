<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\SiteDocumentType;
use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use App\Enums\SiteStatus;
use App\Models\Promoter;
use App\Models\Site;
use App\Models\SiteIncident;
use App\Models\Worker;
use Illuminate\Support\Collection;

/**
 * Indicateurs calculés des chantiers : équipe, pointages, paiements, alertes, jalons et échéances.
 *
 * L’équipe, les pointages et les remplacements proviennent des données de démonstration des ouvriers
 * (affectations sur un chantier du même nom) ; les paiements et les besoins, de celles du promoteur.
 */
class SiteInsights
{
    /**
     * Couleur des libellés calculés (repris de la maquette).
     *
     * @var array<string, string>
     */
    public const TONES = [
        'Complet' => 'g', 'Incomplet' => 'o', 'Non requis' => 'n', 'À jour' => 'g', 'En attente' => 'a', 'Retard de paiement' => 'r', 'À autoriser' => 'a',
        'Anomalies' => 'r', 'Aucun' => 'n', 'Critique' => 'r', 'Élevée' => 'o', 'Moyenne' => 'a', 'Faible' => 'n',
        'Réalisé' => 'g', 'En cours' => 'g', 'En retard' => 'r', 'Prévu' => 'b',
    ];

    /**
     * Raccourcis des cartes KPI : clé d’URL → libellé, icône, ton.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const QUICK = [
        'total' => ['Total des chantiers', 'domain', 'g'],
        'actifs' => ['Chantiers actifs', 'construction', 'g'],
        'brouillons' => ['Brouillons', 'edit_note', 'n'],
        'prets' => ['Prêts à démarrer', 'rocket_launch', 'b'],
        'retard' => ['Chantiers en retard', 'schedule', 'r'],
        'effectif' => ['Effectif incomplet', 'group_remove', 'o'],
        'incidents' => ['Avec incidents', 'report', 'r'],
        'cloturer' => ['À clôturer', 'task_alt', 'p'],
    ];

    /**
     * Alertes et échéances du bas de liste : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const ALERTS = ['retard' => 'Chantiers en retard', 'paiement' => 'Paiements à déclencher', 'remplacement' => 'Remplacements urgents'];

    /** @var Collection<int, Worker> */
    private Collection $workers;

    /** @var Collection<int, Promoter> */
    private Collection $promoters;

    /** @var array<int, array<string, mixed>> */
    private array $flags = [];

    public function __construct()
    {
        $this->workers = Worker::query()->get(['id', 'slug', 'code', 'first_name', 'last_name', 'trade', 'availability', 'demo_relations']);
        $this->promoters = Promoter::query()->get(['id', 'slug', 'name', 'demo_relations'])->keyBy('id');
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function badge(string $label): array
    {
        return ['label' => $label, 'tone' => self::TONES[$label] ?? 'n'];
    }

    /**
     * Affectations des ouvriers sur ce chantier.
     *
     * @return Collection<int, array{worker: Worker, assignment: array<string, mixed>}>
     */
    public function team(Site $site): Collection
    {
        return $this->workers->flatMap(fn (Worker $w): array => collect($w->demo('assignments'))
            ->where('site', $site->name)
            ->map(fn (array $a): array => ['worker' => $w, 'assignment' => $a])
            ->values()->all());
    }

    /**
     * Pointages des ouvriers sur ce chantier, du plus récent au plus ancien.
     *
     * @return Collection<int, array{worker: Worker, timesheet: array<string, mixed>}>
     */
    public function timesheets(Site $site): Collection
    {
        return $this->team($site)->pluck('worker')->unique('id')
            ->flatMap(fn (Worker $w): array => collect($w->demo('timesheets'))
                ->where('site', $site->name)
                ->map(fn (array $t): array => ['worker' => $w, 'timesheet' => $t])
                ->values()->all())
            ->sortByDesc(fn (array $row): string => $row['timesheet']['date'])
            ->values();
    }

    /**
     * Dossiers d’indisponibilité et de remplacement des ouvriers sur ce chantier.
     *
     * @return Collection<int, array{worker: Worker, case: array<string, mixed>}>
     */
    public function replacements(Site $site): Collection
    {
        return $this->workers->flatMap(fn (Worker $w): array => collect($w->demo('replacements'))
            ->where('site', $site->name)
            ->map(fn (array $c): array => ['worker' => $w, 'case' => $c])
            ->values()->all());
    }

    /**
     * Paiements du promoteur liés à ce chantier.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payments(Site $site): Collection
    {
        return collect($this->promoters->get($site->promoter_id)?->demo('payments') ?? [])->where('site', $site->name)->values();
    }

    /**
     * Besoins et offres publiés par le promoteur pour ce chantier.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function offers(Site $site): Collection
    {
        return collect($this->promoters->get($site->promoter_id)?->demo('offers') ?? [])->where('site', $site->name)->values();
    }

    public function promoterSlug(Site $site): ?string
    {
        return $this->promoters->get($site->promoter_id)?->slug;
    }

    /**
     * Signaux d’un chantier : effectif, incidents ouverts, pointages, paiements, retard, niveau d’alerte.
     *
     * @return array{staffing: string, incidents: int, timesheets: string, payments: string, late: bool, alert: string, vacancies: int, replacement: bool, paymentDue: bool}
     */
    public function flags(Site $site): array
    {
        return $this->flags[$site->id] ??= $this->computeFlags($site);
    }

    /**
     * @return array{staffing: string, incidents: int, timesheets: string, payments: string, late: bool, alert: string, vacancies: int, replacement: bool, paymentDue: bool}
     */
    private function computeFlags(Site $site): array
    {
        $status = $site->status;
        $staffing = ! $status->isActive() && $status !== SiteStatus::PendingClosure ? 'Non requis' : ($site->assigned_workers >= $site->required_workers ? 'Complet' : 'Incomplet');
        $incidents = $site->openIncidentsCount();
        $timesheets = match (true) {
            ! $status->isRunning() => 'Aucun',
            $this->timesheets($site)->contains(fn (array $r): bool => in_array($r['timesheet']['status'], ['Contesté', 'À vérifier', 'Incomplet'], true)) => 'Anomalies',
            default => 'À jour',
        };
        $payments = $this->payments($site);
        $paymentStatus = match (true) {
            $payments->contains('status', 'À autoriser') => 'À autoriser',
            $payments->contains(fn (array $p): bool => in_array($p['status'], ['Échoué', 'En attente'], true)) => 'En attente',
            $status === SiteStatus::Late => 'Retard de paiement',
            default => 'À jour',
        };
        $late = $site->isLate();
        $critical = $incidents > 0 && $site->incidents->contains('severity', SiteIncidentSeverity::Critical);
        $alert = match (true) {
            $status === SiteStatus::Suspended || $critical => 'Critique',
            $late || $incidents > 0 => 'Élevée',
            $staffing === 'Incomplet' || $paymentStatus !== 'À jour' => 'Moyenne',
            default => 'Faible',
        };
        $openCase = $this->replacements($site)->contains(fn (array $r): bool => ! in_array($r['case']['status'], ['Clôturé', 'Annulé', 'Refusé'], true));
        $threshold = (int) ceil(max(1, $site->progress) / 50) * 50 - 5;

        return [
            'staffing' => $staffing,
            'incidents' => $incidents,
            'timesheets' => $timesheets,
            'payments' => $paymentStatus,
            'late' => $late,
            'alert' => $alert,
            'vacancies' => $site->vacancies(),
            'replacement' => $openCase || ($staffing === 'Incomplet' && $status->isRunning()),
            'paymentDue' => in_array($status, [SiteStatus::InProgress, SiteStatus::Late, SiteStatus::PendingClosure], true) && ($paymentStatus !== 'À jour' || $site->progress >= $threshold),
        ];
    }

    /**
     * Le chantier relève-t-il du raccourci KPI ?
     */
    public function matchesQuick(Site $site, string $key): bool
    {
        $flags = $this->flags($site);

        return match ($key) {
            'actifs' => in_array($site->status, [SiteStatus::InProgress, SiteStatus::Late, SiteStatus::Recruiting], true),
            'brouillons' => $site->status === SiteStatus::Draft,
            'prets' => in_array($site->status, [SiteStatus::Ready, SiteStatus::Planned], true),
            'retard' => $flags['late'],
            'effectif' => $flags['staffing'] === 'Incomplet',
            'incidents' => $flags['incidents'] > 0,
            'cloturer' => $site->status === SiteStatus::PendingClosure,
            default => true,
        };
    }

    /**
     * Le chantier relève-t-il de l’alerte du bas de liste ?
     */
    public function matchesAlert(Site $site, string $key): bool
    {
        $flags = $this->flags($site);

        return match ($key) {
            'retard' => $flags['late'],
            'paiement' => $flags['paymentDue'],
            'remplacement' => $flags['replacement'],
            default => true,
        };
    }

    /**
     * Ligne de la liste.
     *
     * @return array<string, mixed>
     */
    public function row(Site $site): array
    {
        $flags = $this->flags($site);

        return [
            ...$this->summary($site),
            'type' => $site->type,
            'startsOn' => $site->starts_on->format('Y-m-d'),
            'endsOn' => $site->ends_on->format('Y-m-d'),
            'executedDays' => $site->executedDays(),
            'plannedDays' => $site->planned_days,
            'staffing' => self::badge($flags['staffing']),
            'timesheets' => self::badge($flags['timesheets']),
            'payments' => self::badge($flags['payments']),
            'alert' => self::badge($flags['alert']),
            'info' => self::editable($site),
            'acceptanceVerified' => $this->acceptanceVerified($site),
        ];
    }

    /**
     * Carte (vues Cartes et Carte géographique).
     *
     * @return array<string, mixed>
     */
    public function card(Site $site): array
    {
        $flags = $this->flags($site);

        return [
            ...$this->summary($site),
            'startsOn' => $site->starts_on->format('Y-m-d'),
            'endsOn' => $site->ends_on->format('Y-m-d'),
            'latitude' => $site->latitude,
            'longitude' => $site->longitude,
            'alertText' => match (true) {
                $flags['incidents'] > 0 => "{$flags['incidents']} incident(s)",
                $flags['late'] => 'Retard sur planning',
                $flags['staffing'] === 'Incomplet' => "{$flags['vacancies']} poste(s) vacant(s)",
                default => 'Aucune alerte',
            },
            'alertTone' => $flags['incidents'] > 0 || $flags['late'] ? 'danger' : ($flags['staffing'] === 'Incomplet' ? 'warning' : 'muted'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Site $site): array
    {
        return [
            'id' => $site->id,
            'slug' => $site->slug,
            'name' => $site->name,
            'reference' => $site->reference,
            'promoter' => $site->promoter_name,
            'commune' => $site->commune,
            'progress' => $site->progress,
            'assigned' => $site->assigned_workers,
            'required' => $site->required_workers,
            'status' => ['value' => $site->status->value, 'label' => $site->status->label(), 'tone' => $site->status->tone()],
        ];
    }

    /**
     * Informations modifiables par l’administration.
     *
     * @return array<string, string>
     */
    public static function editable(Site $site): array
    {
        return [
            'name' => $site->name,
            'priority' => $site->priority->value,
            'working_hours' => $site->working_hours ?? '',
            'instructions' => $site->instructions ?? '',
            'access_conditions' => $site->access_conditions ?? '',
            'safety_equipment' => $site->safety_equipment ?? '',
        ];
    }

    public function acceptanceVerified(Site $site): bool
    {
        return $site->documents->contains(fn ($d): bool => $d->type === SiteDocumentType::AcceptanceReport && $d->status === DocumentStatus::Verified);
    }

    /**
     * Échéances du calendrier : début, fin, jalon de mi-parcours, paiements attendus, besoins urgents, remplacements.
     *
     * @param  iterable<Site>  $sites
     * @return array<int, array{date: string, label: string, kind: string, slug: string}>
     */
    public function events(iterable $sites): array
    {
        $today = Site::referenceDate();
        $events = [];
        $push = function (string $date, string $label, string $kind, Site $site) use (&$events): void {
            $events[] = ['date' => $date, 'label' => $label, 'kind' => $kind, 'slug' => $site->slug];
        };

        foreach ($sites as $site) {
            $flags = $this->flags($site);
            $push($site->starts_on->format('Y-m-d'), "Début · {$site->name}", 'start', $site);
            $push($site->ends_on->format('Y-m-d'), ($site->status === SiteStatus::PendingClosure ? 'Clôture · ' : 'Fin prévue · ').$site->name, 'end', $site);
            foreach ($this->payments($site)->whereIn('status', ['À autoriser', 'En attente']) as $payment) {
                $push($today->format('Y-m-d'), "Paiement attendu · {$site->name}", 'payment', $site);
            }
            if ($flags['staffing'] === 'Incomplet') {
                $push($today->addDays(2)->format('Y-m-d'), "Besoin urgent · {$site->name}", 'urgent', $site);
            }
            $push($site->starts_on->copy()->addDays((int) round($site->starts_on->diffInDays($site->ends_on) / 2))->format('Y-m-d'), "Jalon mi-parcours · {$site->name}", 'milestone', $site);
            foreach ($this->replacements($site) as ['worker' => $worker, 'case' => $case]) {
                if (! in_array($case['status'], ['Clôturé', 'Annulé'], true)) {
                    $push($case['start'], "Remplacement · {$worker->name}", 'replacement', $site);
                }
            }
        }

        usort($events, fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $events;
    }

    /**
     * Indicateurs de la fiche.
     *
     * @return array<int, array{label: string, value: string, unit?: string, icon: string, tone: string, hint: string}>
     */
    public function kpis(Site $site): array
    {
        $flags = $this->flags($site);
        $offers = $this->offers($site);
        $open = $offers->whereIn('status', ['Ouverte', 'Candidatures reçues', 'À valider'])->count();

        return [
            ['label' => 'Ouvriers affectés', 'value' => "{$site->assigned_workers} / {$site->required_workers}", 'icon' => 'groups', 'tone' => $flags['staffing'] === 'Incomplet' ? 'a' : 'g', 'hint' => $flags['vacancies'] ? "{$flags['vacancies']} poste(s) vacant(s)" : 'Effectif complet'],
            ['label' => 'Progression', 'value' => "{$site->progress} %", 'icon' => 'trending_up', 'tone' => $site->progress < 50 ? 'a' : 'g', 'hint' => $site->executedDays()." / {$site->planned_days} jours exécutés"],
            ['label' => 'Montant payé', 'value' => self::amount($site->paid_amount), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'a', 'hint' => 'Budget '.self::amount($site->budget).' FCFA'],
            ['label' => 'Besoins liés', 'value' => (string) $offers->count(), 'icon' => 'assignment', 'tone' => 'b', 'hint' => "{$open} ouvert(s)"],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function tabCounts(Site $site): array
    {
        return [
            'incidents' => $site->openIncidentsCount(),
            'documents' => $site->documents->where('status', '!=', DocumentStatus::Verified)->count(),
        ];
    }

    /**
     * Bandeau d’état de la fiche.
     *
     * @return array{tone: string, icon: string, title: string, text: string}|null
     */
    public function banner(Site $site): ?array
    {
        $banner = match ($site->status) {
            SiteStatus::Suspended => ['danger', 'pause_circle', 'Chantier suspendu.', 'Les pointages et paiements sont gelés.'],
            SiteStatus::Cancelled => ['neutral', 'cancel', 'Chantier annulé.', 'Lecture seule.'],
            SiteStatus::Archived => ['neutral', 'archive', 'Chantier archivé.', 'Lecture seule.'],
            SiteStatus::PendingClosure => ['info', 'task_alt', 'Travaux terminés.', 'Vérifiez le PV de réception puis clôturez le chantier.'],
            default => null,
        };

        return $banner ? array_combine(['tone', 'icon', 'title', 'text'], $banner) : null;
    }

    /**
     * Alertes de la vue d’ensemble : incidents ouverts (deux au plus) et retard sur le planning.
     *
     * @return array<int, array{tone: string, icon: string, title: string, text: string, cta: string|null, href: string|null}>
     */
    public function alerts(Site $site): array
    {
        $alerts = $site->incidents
            ->where('status', '!=', SiteIncidentStatus::Resolved)
            ->take(2)
            ->map(fn (SiteIncident $i): array => ['tone' => $i->severity === SiteIncidentSeverity::Critical ? 'danger' : 'warning', 'icon' => 'report', 'title' => $i->type, 'text' => $i->description, 'cta' => 'Voir', 'href' => "/chantiers/{$site->slug}/incidents"])
            ->values()->all();

        if ($this->flags($site)['late']) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'schedule', 'title' => 'Retard sur le planning', 'text' => "Progression {$site->progress} % pour {$site->elapsedPercent()} % du temps consommé.", 'cta' => null, 'href' => null];
        }

        return array_slice($alerts, 0, 3);
    }

    public static function amount(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
