<?php

namespace App\Support;

use App\Enums\NeedDocumentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use App\Models\Need;
use App\Models\NeedLine;
use App\Models\NeedOffer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Indicateurs calculés des besoins : alertes, raccourcis, lignes de liste, cartes et données de la fiche.
 *
 * Les seuils viennent des règles du module (config/admin.php › need_rules), reprises de Paramètres.
 */
class NeedInsights
{
    /**
     * Couleur des libellés de statut et de couverture (repris de la maquette).
     *
     * @var array<string, string>
     */
    public const TONES = [
        Need::SEARCHING => 'b', Need::PARTIAL => 'a', Need::EXPIRED => 'o', Need::REPLACING => 'o', Need::COVERED => 'b', Need::DEPOSIT => 'o', Need::DONE => 'g',
        'En attente de validation du promoteur' => 'p', 'Suspendu' => 'r', 'Annulé' => 'n', 'Clôturé' => 'n', 'Archivé' => 'n',
        'Non couvert' => 'r', 'Faiblement couvert' => 'o', 'Partiellement couvert' => 'a', 'Presque couvert' => 'b', 'Totalement couvert' => 'g', 'Surcouvert' => 'p',
        NeedOffer::PUBLISHED => 'g', NeedOffer::PARTIAL => 'a', NeedOffer::COMPLETE => 'g', NeedOffer::EXPIRED => 'o', 'Suspendue' => 'r', 'Annulée' => 'n', 'Clôturée' => 'n', 'Archivée' => 'n',
        'Réalisé' => 'g', 'En cours' => 'a', 'À venir' => 'n',
    ];

    /**
     * Raccourcis : clé d’URL → libellé, icône, ton.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const QUICK = [
        'attente' => ['Besoins en attente', 'assignment', 'a'],
        'urgents' => ['Besoins urgents', 'crisis_alert', 'r'],
        'expire' => ['Expirent bientôt', 'timer', 'o'],
        'expirees' => ['Besoins expirés', 'timer_off', 'r'],
        'pourvoir' => ['Ouvriers manquants', 'person_add', 'a'],
        'partiel' => ['Partiellement couverts', 'pie_chart', 'b'],
        'sansouv' => ['Sans ouvrier sélectionné', 'person_off', 'o'],
        'taux' => ['Taux global de couverture', 'donut_large', 'p'],
    ];

    /**
     * Alertes du bas de liste : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const BOTTOM = [
        'demarrage' => 'Besoins à démarrer sans effectif suffisant',
        'critique' => 'Besoins critiques sans affectation',
        'expire' => 'Besoins avec une offre bientôt expirée',
    ];

    /**
     * Onglets de la liste : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const TABS = ['attente' => 'En attente d’ouvriers', 'expires' => 'Expirés', 'suspendus' => 'Suspendus et clôturés'];

    /**
     * Zone commerciale d’une commune.
     *
     * @var array<string, string>
     */
    public const ZONES = [
        'Cocody' => 'Abidjan Est', 'Bingerville' => 'Abidjan Est', 'Plateau' => 'Abidjan Centre', 'Adjamé' => 'Abidjan Centre', 'Attécoubé' => 'Abidjan Centre',
        'Treichville' => 'Abidjan Sud', 'Marcory' => 'Abidjan Sud', 'Koumassi' => 'Abidjan Sud', 'Port-Bouët' => 'Abidjan Sud', 'Vridi' => 'Abidjan Sud',
        'Yopougon' => 'Abidjan Nord-Ouest', 'Abobo' => 'Abidjan Nord-Ouest', 'Anyama' => 'Abidjan Nord-Ouest', 'Songon' => 'Abidjan Nord-Ouest',
    ];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $alerts = [];

    /**
     * Relations à charger pour tous les calculs.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['site', 'lines', 'offers.line', 'offers.assignments', 'assignments', 'documents'];

    /**
     * @return array<string, int>
     */
    public static function rules(): array
    {
        return config('admin.need_rules');
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function badge(string $label): array
    {
        return ['label' => $label, 'tone' => self::TONES[$label] ?? 'n'];
    }

    public static function zone(string $commune): string
    {
        return self::ZONES[$commune] ?? 'Intérieur du pays';
    }

    private static function today(): CarbonImmutable
    {
        return Need::referenceDate();
    }

    /**
     * Jours entre aujourd’hui et le démarrage (négatif s’il est passé).
     */
    public static function daysToStart(Need $need): int
    {
        return (int) self::today()->diffInDays($need->starts_on, false);
    }

    /**
     * Le besoin relève-t-il de l’onglet ?
     */
    public function inTab(Need $need, string $tab): bool
    {
        return match ($tab) {
            'expires' => ! $need->manual_status && $need->status() === Need::EXPIRED,
            'suspendus' => (bool) $need->manual_status,
            default => $need->isPending(),
        };
    }

    public function matchesQuick(Need $need, string $key): bool
    {
        $pending = $need->isPending();

        return match ($key) {
            'attente' => $pending,
            'urgents' => $pending && $need->urgency->isPressing(),
            'expire' => $pending && $need->offers->contains(fn (NeedOffer $o): bool => $o->isOpen() && $o->daysLeft() >= 0 && $o->daysLeft() <= self::rules()['expiry_alert_days']),
            'expirees' => $need->status() === Need::EXPIRED,
            'pourvoir' => $pending && $need->coverage()['rest'] > 0,
            'partiel' => $need->status() === Need::PARTIAL,
            'sansouv' => $need->status() === Need::SEARCHING,
            default => true,
        };
    }

    public function matchesBottom(Need $need, string $key): bool
    {
        return collect($this->alerts($need))->contains('key', $key);
    }

    /**
     * Valeurs des raccourcis (compteurs, ouvriers manquants, taux global) et délai moyen de couverture.
     *
     * @param  EloquentCollection<int, Need>  $needs
     * @return array<string, string>
     */
    public function quickValues(EloquentCollection $needs): array
    {
        $active = $needs->filter(fn (Need $n): bool => ! $n->manual_status);
        $need = $active->sum(fn (Need $n): int => $n->coverage()['need']);
        $selected = $active->sum(fn (Need $n): int => $n->coverage()['selected']);
        $full = $needs->flatMap->offers->filter(fn (NeedOffer $o): bool => $o->stats()['selected'] >= $o->line->quantity);
        $delay = $full->isEmpty() ? 0 : (int) round($full->avg(function (NeedOffer $o): int {
            $last = $o->assignments->filter(fn ($a): bool => $a->status->isSelected())->max('selected_on') ?? $o->published_on;

            return max(1, (int) $o->published_on->diffInDays($last));
        }));
        $values = [];
        foreach (array_keys(self::QUICK) as $key) {
            $values[$key] = (string) $needs->filter(fn (Need $n): bool => $this->matchesQuick($n, $key))->count();
        }
        $pending = $needs->filter(fn (Need $n): bool => $n->isPending());

        return [
            ...$values,
            'pourvoir' => (string) $needs->filter(fn (Need $n): bool => $this->matchesQuick($n, 'pourvoir'))->sum(fn (Need $n): int => $n->coverage()['rest']),
            'taux' => ($need ? (int) round($selected / $need * 100) : 0).' %',
            'delai' => "{$delay} j",
            'pendingNeed' => (string) $pending->sum(fn (Need $n): int => $n->coverage()['need']),
            'pendingSelected' => (string) $pending->sum(fn (Need $n): int => $n->coverage()['selected']),
        ];
    }

    /**
     * Données d’une offre utiles aux actions (prolonger, actualiser, clôturer les candidatures).
     *
     * @return array<string, mixed>
     */
    public static function offerRef(NeedOffer $offer): array
    {
        $stats = $offer->stats();

        return [
            'id' => $offer->id,
            'reference' => $offer->reference,
            'trade' => $offer->line->trade,
            'quantity' => $offer->line->quantity,
            'selected' => $stats['selected'],
            'rest' => $stats['rest'],
            'deadline' => $offer->deadline->format('Y-m-d'),
            'startsOn' => $offer->starts_on->format('Y-m-d'),
            'status' => self::badge($offer->status()),
            'applicationsOpen' => $offer->applications_open,
            'startPassed' => $offer->starts_on->lessThanOrEqualTo(self::today()->addDay()),
        ];
    }

    /**
     * Alertes d’un besoin (aucune quand l’administration a posé son statut).
     *
     * @return array<int, array{key: string, tone: string, icon: string, title: string, text: string, cta: string|null, action: array<string, mixed>|null}>
     */
    public function alerts(Need $need): array
    {
        return $this->alerts[$need->id] ??= $this->computeAlerts($need);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeAlerts(Need $need): array
    {
        if ($need->manual_status) {
            return [];
        }

        $rules = self::rules();
        $coverage = $need->coverage();
        $pending = in_array($need->status(), [Need::SEARCHING, Need::PARTIAL, Need::EXPIRED], true);
        $toStart = self::daysToStart($need);
        $base = "/besoins/{$need->slug}";
        $date = fn ($d): string => $d->format('d/m/Y');
        $out = [];
        $add = function (string $key, string $tone, string $icon, string $title, string $text, ?string $cta = null, ?array $action = null) use (&$out): void {
            $out[] = compact('key', 'tone', 'icon', 'title', 'text', 'cta', 'action');
        };
        $live = $need->offers->filter(fn (NeedOffer $o): bool => ! $o->manual_status);

        if ($need->anomalies) {
            $add('anomalie', 'danger', 'report', 'Anomalie détectée', implode(' · ', $need->anomalies), 'Voir les documents', ['type' => 'link', 'href' => "{$base}/documents"]);
        }
        if ($need->ends_on->lessThan($need->starts_on) || $live->contains(fn (NeedOffer $o): bool => $o->deadline->greaterThan($o->starts_on))) {
            $add('dates', 'danger', 'event_busy', 'Incohérence entre dates', $need->ends_on->lessThan($need->starts_on)
                ? "La date de fin ({$date($need->ends_on)}) précède la date de début ({$date($need->starts_on)})."
                : 'Une date limite de candidature est postérieure au démarrage.');
        }
        if ($need->urgency === NeedUrgency::Critical && $coverage['selected'] === 0 && $pending) {
            $add('critique', 'danger', 'crisis_alert', 'Besoin critique sans affectation', 'Aucune affectation confirmée · couverture exigée avant le '.$date($need->coverage_deadline ?? $need->starts_on).'.', 'Lancer le matching', ['type' => 'match']);
        }
        if ($pending && $toStart <= $rules['start_alert_days'] && $toStart >= -2 && $coverage['percent'] < $rules['minimum_coverage']) {
            $add('demarrage', 'danger', 'event_upcoming', 'Démarrage proche sans effectif suffisant', 'Démarrage '.($toStart >= 0 ? "dans {$toStart} j" : 'dépassé de '.(-$toStart).' j')." · couverture {$coverage['percent']} % (minimum attendu {$rules['minimum_coverage']} %).", 'Lancer le matching', ['type' => 'match']);
        }
        foreach ($live as $offer) {
            $stats = $offer->stats();
            $left = $offer->daysLeft();
            if ($offer->isOpen() && $left >= 0 && $left <= $rules['expiry_alert_days']) {
                $add('expire', 'warning', 'timer', 'Offre bientôt expirée', "{$offer->reference} · date limite le {$date($offer->deadline)} ({$left} j).", 'Prolonger', ['type' => 'extend', 'offer' => $offer->id]);
            }
            if ($offer->status() === NeedOffer::EXPIRED) {
                $add('expiree', 'danger', 'timer_off', 'Poste expiré'.($stats['selected'] ? ' partiellement couvert' : ' sans couverture'), "{$offer->line->trade} · {$offer->reference} · {$stats['selected']} / {$offer->line->quantity} poste(s) couvert(s).", 'Actualiser l’offre', ['type' => 'refresh', 'offer' => $offer->id]);
            }
            if ($offer->applications_open && $stats['selected'] >= $offer->line->quantity) {
                $add('complete', 'info', 'task_alt', 'Offre complète toujours ouverte', "{$offer->reference} · {$stats['selected']} / {$offer->line->quantity} affectations confirmées.", 'Clôturer les candidatures', ['type' => 'closeApplications', 'offer' => $offer->id]);
            }
        }
        if ($coverage['over']) {
            $add('surcouv', 'info', 'group_add', 'Surcouverture détectée', "{$coverage['selected']} affectations confirmées pour {$coverage['need']} poste(s) demandés.", 'Voir les affectations', ['type' => 'link', 'href' => "{$base}/affectations"]);
        }

        return $out;
    }

    /**
     * Ce qu’il faut au front pour proposer les actions d’un besoin.
     *
     * @return array<string, mixed>
     */
    public function actionRef(Need $need): array
    {
        $coverage = $need->coverage();

        return [
            'slug' => $need->slug,
            'reference' => $need->reference,
            'today' => self::today()->format('Y-m-d'),
            'status' => self::badge($need->status()),
            'manual' => $need->manual_status?->value,
            'covered' => in_array($need->status(), Need::COVERED_STATUSES, true),
            'done' => $need->status() === Need::DONE,
            'selected' => $coverage['selected'],
            'need' => $coverage['need'],
            'percent' => $coverage['percent'],
            'canMatch' => ! $need->manual_status && $need->offers->contains(fn (NeedOffer $o): bool => $o->isOpen() && $o->stats()['rest'] > 0),
            'expiredOffers' => $need->offers->filter(fn (NeedOffer $o): bool => $o->status() === NeedOffer::EXPIRED)->map(fn (NeedOffer $o): array => self::offerRef($o))->values()->all(),
        ];
    }

    /**
     * Ligne de la liste.
     *
     * @return array<string, mixed>
     */
    public function row(Need $need): array
    {
        $coverage = $need->coverage();
        $trades = $need->lines->pluck('trade');
        $deadline = $need->offers->reject(fn (NeedOffer $o): bool => (bool) $o->manual_status)->min('deadline');

        return [
            'id' => $need->id,
            'slug' => $need->slug,
            'reference' => $need->reference,
            'site' => $need->site->name,
            'siteType' => $need->site->type,
            'promoter' => $need->site->promoter_name,
            'commune' => $need->site->commune,
            'city' => $need->site->city,
            'mainTrade' => $trades->first(),
            'tradesCount' => $trades->count(),
            'selected' => $coverage['selected'],
            'need' => $coverage['need'],
            'rest' => $coverage['rest'],
            'percent' => $coverage['percent'],
            'startsOn' => $need->starts_on->format('Y-m-d'),
            'deadline' => $deadline?->format('Y-m-d'),
            'duration' => $need->duration(),
            'urgency' => ['value' => $need->urgency->value, 'label' => $need->urgency->label(), 'tone' => $need->urgency->tone()],
            'type' => $need->type,
            'status' => self::badge($need->status()),
            'coverage' => self::badge($coverage['label']),
            'createdOn' => $need->created_on->format('Y-m-d'),
            'actions' => $this->actionRef($need),
        ];
    }

    /**
     * Carte (vue Cartes).
     *
     * @return array<string, mixed>
     */
    public function card(Need $need): array
    {
        $alert = $this->alerts($need)[0] ?? null;
        $coverage = $need->coverage();

        return [
            'id' => $need->id,
            'slug' => $need->slug,
            'site' => $need->site->name,
            'reference' => $need->reference,
            'siteType' => $need->site->type,
            'promoter' => $need->site->promoter_name,
            'commune' => $need->site->commune,
            'status' => self::badge($need->status()),
            'urgency' => ['label' => 'Urgence '.mb_strtolower($need->urgency->label()), 'tone' => $need->urgency->tone()],
            'line' => $need->lines->map(fn (NeedLine $l): string => "{$l->quantity} ".mb_strtolower($l->trade))->implode(', ').' · début '.$need->starts_on->format('d/m/Y'),
            'percent' => $coverage['percent'],
            'alert' => $alert ? ['text' => $alert['title'], 'tone' => $alert['tone']] : ['text' => "{$coverage['selected']} affectation(s) confirmée(s)", 'tone' => 'muted'],
        ];
    }

    /**
     * Bandeau d’état de la fiche.
     *
     * @return array{tone: string, icon: string, title: string, text: string, cta: bool}|null
     */
    public function banner(Need $need): ?array
    {
        $coverage = $need->coverage();
        $promoter = $need->site->promoter_name;
        $banner = match ($need->status()) {
            'Suspendu' => ['danger', 'pause_circle', 'Besoin suspendu.', $need->reason ?: 'Les offres sont suspendues.'],
            'Annulé' => ['neutral', 'cancel', 'Besoin annulé.', $need->reason ?: 'Lecture seule.'],
            'Clôturé' => ['neutral', 'task_alt', 'Besoin clôturé.', 'Lecture seule.'],
            'Archivé' => ['neutral', 'archive', 'Besoin archivé.', 'Lecture seule.'],
            Need::EXPIRED => ['warning', 'timer_off', 'Besoin expiré.', ($coverage['selected'] ? "{$coverage['selected']} ouvrier(s) trouvé(s) restent affectés. " : '')."Actualisez le besoin pour rechercher les {$coverage['rest']} ouvrier(s) manquant(s)."],
            Need::COVERED => ['info', 'event_available', 'Besoin entièrement couvert.', 'Les ouvriers sélectionnés sont à contacter et à valider dans Affectations & Offres.'],
            Need::DEPOSIT => ['warning', 'payments', 'Ouvriers validés.', "En attente du paiement de l’acompte par {$promoter} selon l’échéancier."],
            Need::REPLACING => ['warning', 'swap_horiz', 'Remplacement en cours.', 'Un ou plusieurs ouvriers doivent être remplacés avant la reprise complète.'],
            Need::DONE => ['success', 'task_alt', 'Besoin comblé.', 'Validé par le promoteur · chantier en cours.'],
            default => null,
        };

        return $banner ? [...array_combine(['tone', 'icon', 'title', 'text'], $banner), 'cta' => in_array($need->status(), Need::COVERED_STATUSES, true)] : null;
    }

    /**
     * Indicateurs de la fiche.
     *
     * @return array<int, array{label: string, value: string, icon: string, tone: string, hint: string}>
     */
    public function kpis(Need $need): array
    {
        $c = $need->coverage();
        $min = self::rules()['minimum_coverage'];

        return [
            ['label' => 'Postes demandés', 'value' => (string) $c['need'], 'icon' => 'groups', 'tone' => 'b', 'hint' => $need->lines->count().' métier(s)'],
            ['label' => 'Ouvriers trouvés', 'value' => "{$c['selected']} / {$c['need']}", 'icon' => 'how_to_reg', 'tone' => 'g', 'hint' => "{$c['validated']} validé(s) · restent affectés même si le besoin expire"],
            ['label' => 'Postes restants', 'value' => (string) $c['rest'], 'icon' => 'person_add', 'tone' => $c['rest'] ? 'a' : 'g', 'hint' => $c['over'] ? 'Surcouverture : '.($c['selected'] - $c['need']).' en plus' : ($c['rest'] ? 'À pourvoir' : 'Besoin couvert')],
            ['label' => 'Taux de couverture', 'value' => "{$c['percent']} %", 'icon' => 'donut_large', 'tone' => $c['percent'] >= $min ? 'g' : 'a', 'hint' => "Minimum attendu {$min} % · Paramètres"],
        ];
    }

    /**
     * Chronologie du besoin, de la publication à la clôture.
     *
     * @return array<int, array{label: string, date: string|null, status: string}>
     */
    public function chronology(Need $need): array
    {
        $c = $need->coverage();
        $full = $c['selected'] >= $c['need'];
        $validated = $need->promoter_validated;
        $today = self::today();
        $step = fn (string $label, bool $done, bool $current, $date = null): array => ['label' => $label, 'date' => $date?->format('Y-m-d'), 'status' => $done ? 'Réalisé' : ($current ? 'En cours' : 'À venir')];

        return [
            $step('Création et publication automatique', true, false, $need->created_on),
            $step("Ouvriers trouvés ({$c['selected']} / {$c['need']})", $full, ! $full),
            $step("Ouvriers contactés et validés ({$c['validated']} / {$c['need']})", $c['validated'] >= $c['need'], $c['validated'] > 0 && $c['validated'] < $c['need']),
            $step('Validation du promoteur', $validated, $full && ! $validated),
            $step('Chantier en cours', $validated && $need->starts_on->lessThanOrEqualTo($today), $validated && $need->starts_on->greaterThan($today), $need->starts_on),
            $step('Clôture', in_array($need->manual_status, [NeedManualStatus::Closed, NeedManualStatus::Archived], true), false, $need->ends_on),
        ];
    }

    /**
     * Budget prévisionnel : effectif × coût journalier × jours ouvrés.
     */
    public static function budget(Need $need): int
    {
        $days = (int) round($need->duration() * 6 / 7);

        return (int) $need->lines->sum(fn (NeedLine $l): int => $l->quantity * $l->daily_cost * $days);
    }

    /**
     * @return array<string, int>
     */
    public function tabCounts(Need $need): array
    {
        return [
            '' => count($this->alerts($need)),
            'metiers' => $need->coverage()['rest'],
            'documents' => $need->documents->where('status', '!=', NeedDocumentStatus::Validated)->count(),
        ];
    }
}
