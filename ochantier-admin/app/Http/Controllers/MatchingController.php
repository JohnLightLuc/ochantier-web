<?php

namespace App\Http\Controllers;

use App\Models\MatchingAudit;
use App\Models\MatchingCycle;
use App\Models\MatchingNotification;
use App\Models\MatchingRecalculation;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\NeedOffer;
use App\Support\MatchingEngine;
use App\Support\MatchingPresenter;
use App\Support\NeedInsights;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pages du Matching : écran principal, résultats, sélections à valider, historique, analyses,
 * assistant de lancement, fiche d’un besoin (offre) et fiche d’un match.
 */
class MatchingController extends Controller
{
    /**
     * Critères de tri des profils : clé → libellé.
     */
    public const SORTS = ['pertinence' => 'Pertinence', 'score' => 'Score global', 'exp' => 'Expérience', 'prox' => 'Proximité', 'cout' => 'Coût', 'dispo' => 'Disponibilité', 'note' => 'Note', 'fiab' => 'Fiabilité', 'activite' => 'Dernière activité'];

    /**
     * Filtres de l’écran principal : clé d’URL → libellé.
     */
    private const FILTERS = ['ch' => 'Chantier', 'promo' => 'Promoteur', 'ville' => 'Ville', 'loc' => 'Commune', 'urg' => 'Urgence', 'type' => 'Type de mission', 'cov' => 'Couverture', 'mst' => 'Statut du matching', 'resp' => 'Responsable', 'per' => 'Démarrage'];

    public const NEED_TABS = ['' => 'Vue d’ensemble', 'resultats' => 'Résultats', 'comparaison' => 'Comparaison', 'selection' => 'Sélection et budget', 'exclus' => 'Profils exclus'];

    public const MATCH_TABS = ['' => 'Vue d’ensemble', 'score' => 'Détail du score', 'besoin' => 'Besoin et critères', 'profil' => 'Profil de l’ouvrier', 'validation' => 'Validation', 'affectation' => 'Affectation', 'resultat' => 'Résultat réel', 'historique' => 'Historique'];

    /**
     * Valeur d’un filtre de l’écran principal pour une offre.
     */
    private function filterValue(MatchingEngine $engine, NeedOffer $o, string $key): string
    {
        $days = (int) $engine->today()->diffInDays($o->starts_on, false);

        return match ($key) {
            'ch' => $o->need->site->name,
            'promo' => $o->need->site->promoter_name,
            'ville' => $o->need->site->city,
            'loc' => $o->need->site->commune,
            'urg' => $o->urgency->label(),
            'type' => $o->line->pay_type,
            'cov' => Need::coverageLabel($o->stats()['percent']),
            'mst' => $engine->offerStatus($o),
            'resp' => (string) $o->need->admin,
            default => $days <= 7 ? 'Sous 7 jours' : ($days <= 30 ? 'Sous 30 jours' : 'Au-delà de 30 jours'),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function sort(array $items, string $key): array
    {
        usort($items, fn (array $a, array $b): int => match ($key) {
            'score' => $b['sc']['score'] <=> $a['sc']['score'],
            'exp' => $b['p']['experience'] <=> $a['p']['experience'],
            'prox' => $a['p']['km'] <=> $b['p']['km'],
            'cout' => $a['p']['cost'] <=> $b['p']['cost'],
            'dispo' => $a['p']['availableOn'] <=> $b['p']['availableOn'],
            'note' => $b['p']['rating'] <=> $a['p']['rating'],
            'fiab' => $b['p']['acceptance'] <=> $a['p']['acceptance'],
            'activite' => $b['p']['lastActivity'] <=> $a['p']['lastActivity'],
            default => ($b['sc']['score'] <=> $a['sc']['score']) ?: ((int) (bool) $a['p']['conflict'] <=> (int) (bool) $b['p']['conflict']),
        });

        return $items;
    }

    /**
     * Écran principal : besoins à pourvoir, profils proposés automatiquement par poste, sélection et validation groupée.
     */
    public function index(Request $request, MatchingEngine $engine): Response
    {
        $presenter = new MatchingPresenter($engine);
        $filters = $request->only(['besoins', 'besoin', 'q', 'metier', 'tri', 'alt', 'liste', 'plus', ...array_keys(self::FILTERS)]);
        $eligible = $engine->eligible();
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $offers = $eligible->filter(fn (NeedOffer $o): bool => (empty($filters['metier']) || $o->line->trade === $filters['metier'])
            && ($q === '' || str_contains(mb_strtolower(implode(' ', [$o->reference, $o->need->site->name, $o->need->site->promoter_name, $o->line->trade, $o->need->site->commune, $o->need->district])), $q))
            && collect(array_keys(self::FILTERS))->every(fn (string $k): bool => empty($filters[$k]) || $this->filterValue($engine, $o, $k) === $filters[$k]))->values();

        $needIds = $offers->pluck('need_id')->unique()->values();
        $selected = match (true) {
            ! empty($filters['besoin']) => $needIds->filter(fn (int $id): bool => $offers->firstWhere('need_id', $id)->need->slug === $filters['besoin'])->values(),
            array_key_exists('besoins', $filters) => $needIds->filter(fn (int $id): bool => in_array($offers->firstWhere('need_id', $id)->need->slug, array_filter(explode(',', (string) $filters['besoins'])), true))->values(),
            default => $needIds,
        };
        $current = $selected->flatMap(fn (int $id): Collection => $eligible->where('need_id', $id))->values();
        $auto = $engine->autoPicks();
        $sort = array_key_exists($filters['tri'] ?? '', self::SORTS) ? $filters['tri'] : 'pertinence';
        $alt = array_map('intval', array_filter(explode(',', (string) ($filters['alt'] ?? ''))));

        $needs = $needIds->map(function (int $id) use ($eligible, $selected, $auto, $engine): array {
            $list = $eligible->where('need_id', $id);
            $need = $list->first()->need;
            $rest = $list->sum(fn (NeedOffer $o): int => $o->stats()['rest']);
            $urgent = $list->sortBy(fn (NeedOffer $o): int => MatchingEngine::URGENCY_ORDER[$o->urgency->value])->first()->urgency;
            $last = $list->map->last_matched_on->filter()->max();

            return [
                'id' => $id,
                'slug' => $need->slug,
                'name' => "{$need->site->name} · {$need->reference}",
                'short' => "{$need->reference} · {$need->site->name}",
                'line' => $list->count().' métier'.($list->count() > 1 ? 's' : '')." · {$rest} ouvrier".($rest > 1 ? 's' : '').' restant'.($rest > 1 ? 's' : '').' sur '.$list->sum(fn (NeedOffer $o): int => $o->line->quantity),
                'location' => "{$need->site->commune} · {$need->district}",
                'startsOn' => $list->min('starts_on')->format('Y-m-d'),
                'urgency' => ['label' => $urgent->label(), 'tone' => $urgent->tone()],
                'meta' => $list->map(fn (NeedOffer $o): string => "{$o->line->trade} ×{$o->stats()['rest']}")->implode(' · ')." · {$need->site->promoter_name}",
                'auto' => 'Matching automatique · '.$list->sum(fn (NeedOffer $o): int => count($auto[$o->id]['pick'] ?? [])).' profil(s) proposé(s) · calculé le '.($last ?? $engine->today())->format('d/m/Y'),
                'on' => $selected->contains($id),
            ];
        })->values();

        $groups = $current->map(function (NeedOffer $o, int $i) use ($engine, $presenter, $current, $sort, $alt): array {
            $selection = $engine->selection($o);
            $rest = $o->stats()['rest'];
            $pool = self::sort(array_values(array_filter($engine->results($o)['ok'], fn (array $x): bool => ! preg_match('/Transmis|Confirmé|Démarré/', $engine->recommendationStatus($o, $x)))), $sort);
            $chosen = array_values(array_filter($pool, fn (array $x): bool => in_array($x['p']['key'], $selection['main'], true)));
            $altOn = in_array($o->id, $alt, true);
            $first = array_slice($pool, 0, $rest + 3);
            $picks = $altOn ? [...$first, ...array_filter($chosen, fn (array $x): bool => ! in_array($x, $first, true))] : ($chosen ?: array_slice($pool, 0, $rest));
            $siblings = $current->where('need_id', $o->need_id);
            $needRest = $siblings->sum(fn (NeedOffer $y): int => $y->stats()['rest']);
            $needSelected = $siblings->sum(fn (NeedOffer $y): int => count($engine->selection($y)['main']));
            $isFirst = $i === 0 || $current[$i - 1]->need_id !== $o->need_id;

            return [
                'offer' => $presenter->offer($o),
                'head' => $isFirst ? [
                    'title' => "{$o->need->reference} · {$o->need->site->name}",
                    'sub' => "{$o->need->site->promoter_name} · {$o->need->site->commune} · ".$siblings->map(fn (NeedOffer $y): string => "{$y->line->trade} ×{$y->stats()['rest']}")->implode(' · '),
                    'badge' => "{$needSelected} / {$needRest} ouvrier(s) matché(s)",
                    'complete' => $needSelected >= $needRest,
                    'offers' => $siblings->pluck('id')->values()->all(),
                    'selected' => $needSelected,
                ] : null,
                'altOn' => $altOn,
                'alternatives' => max(0, min(count($pool), $rest + 3) - count($selection['main'])),
                'label' => $altOn ? count($picks).' profils du même métier classés par score' : 'Ouvrier'.($rest > 1 ? 's' : '').' matché'.($rest > 1 ? 's' : '').' automatiquement',
                'chosen' => count($selection['main']),
                'selection' => $presenter->selection($o),
                'picks' => array_map(fn (array $x): array => $presenter->profile($o, $x), $picks),
            ];
        })->values();

        $budgets = $current->map(fn (NeedOffer $o): array => $engine->budget($o, $engine->selection($o)['main']));
        $rest = $current->sum(fn (NeedOffer $o): int => $o->stats()['rest']);
        $selectedCount = $current->sum(fn (NeedOffer $o): int => count($engine->selection($o)['main']));
        $conflicts = $current->sum(fn (NeedOffer $o): int => collect($engine->selection($o)['main'])->filter(fn (string $k): bool => (bool) ($engine->item($o, $k)['p']['conflict'] ?? false))->count());
        $replacements = $current->sum(fn (NeedOffer $o): int => count($engine->selection($o)['replacements']));
        $first = $current->first();
        $success = $engine->success();
        $config = MatchingEngine::config();

        $alerts = [];
        if (! $config['ok']) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'settings_alert', 'title' => 'Configuration du moteur de Matching requise dans Paramètres.', 'text' => "Les pondérations totalisent {$config['sum']} % : le lancement est bloqué.", 'cta' => 'Ouvrir Paramètres', 'action' => 'settings'];
        }
        if ($first && $engine->offerStatus($first) === 'À relancer') {
            $alerts[] = ['tone' => 'warning', 'icon' => 'update', 'title' => 'Résultat obsolète', 'text' => 'Dernier calcul le '.$first->last_matched_on->format('d/m/Y')." (plus de {$config['obsolete_days']} jours · Paramètres).", 'cta' => 'Actualiser', 'action' => 'refresh'];
        }
        if ($first && ! $engine->results($first)['ok']) {
            $excluded = $engine->results($first)['ex'];
            $alerts[] = ['tone' => 'danger', 'icon' => 'person_off', 'title' => 'Aucun profil compatible', 'text' => count($excluded).' profil(s) exclu(s) : '.collect($excluded)->map(fn (array $x): string => $x['sc']['blocking'][0]['criterion'])->unique()->implode(', ').'.', 'cta' => 'Voir les causes', 'action' => "/matching/besoins/{$first->id}/exclus"];
        }

        return Inertia::render('Matching/Index', [
            'filters' => array_filter($filters, fn ($v): bool => $v !== null),
            'filterDefs' => collect(self::FILTERS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'options' => $eligible->map(fn (NeedOffer $o): string => $this->filterValue($engine, $o, $key))->filter()->unique()->sort()->values()->all()])->values()->all(),
            'trades' => $eligible->map(fn (NeedOffer $o): string => $o->line->trade)->unique()->sort()->values()->all(),
            'sorts' => self::SORTS,
            'sort' => $sort,
            'kpis' => [
                ['label' => 'Besoins en attente', 'value' => (string) $eligible->pluck('need_id')->unique()->count(), 'icon' => 'description', 'tone' => 'g', 'hint' => $eligible->count().' poste(s) ouvert(s) à pourvoir'],
                ['label' => 'Métiers concernés', 'value' => (string) $eligible->map(fn (NeedOffer $o): string => $o->line->trade)->unique()->count(), 'icon' => 'engineering', 'tone' => 'b', 'hint' => 'Métiers avec des ouvriers manquants'],
                ['label' => 'Ouvriers recherchés', 'value' => (string) $eligible->sum(fn (NeedOffer $o): int => $o->stats()['rest']), 'icon' => 'groups', 'tone' => 'a', 'hint' => 'Postes restant à couvrir'],
                ['label' => 'Taux de réussite', 'value' => "{$success['rate']} %", 'icon' => 'target', 'tone' => 'g', 'hint' => "{$config['success_days']} derniers jours · voir le calcul"],
            ],
            'toValidate' => $this->toValidateCount($engine),
            'needs' => $needs,
            'currentOffer' => $first?->id,
            'groups' => $groups,
            'sums' => [
                'required' => $rest, 'selected' => $selectedCount, 'replacements' => $replacements, 'conflicts' => $conflicts,
                'total' => $budgets->sum('total'), 'available' => $budgets->sum('available'),
            ],
            'alerts' => $alerts,
            'configOk' => $config['ok'],
        ]);
    }

    private function toValidateCount(MatchingEngine $engine): int
    {
        return $engine->offers()->sum(fn (NeedOffer $o): int => count($engine->selection($o)['main']));
    }

    /**
     * Recommandations actives (premiers profils) de toutes les offres éligibles.
     */
    public function results(MatchingEngine $engine): Response
    {
        $presenter = new MatchingPresenter($engine);
        $rows = $engine->eligible()->flatMap(fn (NeedOffer $o): array => array_map(fn (array $x): array => [...$presenter->profile($o, $x), 'offer' => $this->offerLabel($o)], $engine->results($o)['top']))->values();

        return Inertia::render('Matching/Results', [
            'rows' => $rows,
            'sorts' => self::SORTS,
            'levels' => array_keys(config('admin.matching.thresholds')),
            'header' => $this->header('Résultats du Matching', 'Profils recommandés pour tous les besoins éligibles', [['Matching', '/matching'], ['Résultats']]),
        ]);
    }

    /**
     * Export CSV des recommandations actives.
     */
    public function export(MatchingEngine $engine): StreamedResponse
    {
        $rows = $engine->eligible()->flatMap(fn (NeedOffer $o): array => array_map(fn (array $x): array => [$x['id'], $x['p']['name'], $o->reference, $x['sc']['score'], $x['sc']['level'], $x['p']['km'], $x['p']['cost']], $engine->results($o)['top']));
        MatchingAudit::record('a exporté les résultats du Matching', null, $rows->count().' ligne(s)');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Match', 'Ouvrier', 'Besoin', 'Score', 'Niveau', 'Distance (km)', 'Coût (FCFA/j)'], ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, 'matching-resultats-'.Need::referenceDate()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Sélections non vides, en attente d’une décision humaine.
     */
    public function toValidate(MatchingEngine $engine): Response
    {
        $presenter = new MatchingPresenter($engine);
        $rows = $engine->offers()->filter(fn (NeedOffer $o): bool => (bool) $engine->selection($o)['main'])->map(fn (NeedOffer $o): array => [
            'offer' => $presenter->offer($o),
            'selection' => $presenter->selection($o),
        ])->values();

        return Inertia::render('Matching/ToValidate', [
            'rows' => $rows,
            'header' => $this->header('Matchs à valider', 'Sélections en attente d’une décision humaine', [['Matching', '/matching'], ['À valider']]),
        ]);
    }

    /**
     * Cycles, notifications et journal d’audit.
     */
    public function history(): Response
    {
        $date = fn ($d): array => ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i')];

        return Inertia::render('Matching/History', [
            'cycles' => MatchingCycle::query()->latest('ran_at')->latest('id')->get()->map(fn (MatchingCycle $c): array => [
                ...$c->only(['id', 'reference', 'scope', 'needs', 'workers', 'recommendations', 'selections', 'validations', 'assignments', 'successes', 'failures', 'duration', 'initiator', 'algorithm', 'zone', 'trade']),
                ...$date($c->ran_at),
                'status' => MatchingPresenter::status($c->status),
            ])->all(),
            'notifications' => MatchingNotification::query()->latest('sent_at')->latest('id')->get()->map(fn (MatchingNotification $n): array => [
                ...$n->only(['id', 'type', 'recipient', 'channel', 'content', 'action']), ...$date($n->sent_at), 'status' => MatchingPresenter::status($n->status),
            ])->all(),
            'audit' => MatchingAudit::query()->latest('occurred_at')->latest('id')->take(300)->get()->map(fn (MatchingAudit $a): array => [
                'id' => $a->id, ...$date($a->occurred_at), 'author' => $a->author, 'action' => $a->action, 'newValue' => $a->subject, 'oldValue' => null, 'comment' => $a->detail, 'origin' => 'Back-office',
                'category' => ['value' => 'audit', 'label' => 'Audit', 'tone' => 'purple', 'icon' => 'policy'],
            ])->all(),
            'filterOptions' => [
                'scopes' => Need::with('site')->get()->flatMap(fn (Need $n): array => [$n->site->name, $n->site->promoter_name])->unique()->sort()->values()->all(),
            ],
            'today' => Need::referenceDate()->format('Y-m-d'),
            'header' => $this->header('Historique des matchings', 'Cycles, notifications et journal d’audit', [['Matching', '/matching'], ['Historique']]),
        ]);
    }

    /**
     * Indicateurs de performance du Matching.
     */
    public function analyses(MatchingEngine $engine): Response
    {
        return Inertia::render('Matching/Analyses', [
            'success' => $engine->success(),
            'header' => $this->header('Analyses et performances', 'Taux de réussite = matchs démarrés ÷ affectations issues du Matching et confirmées × 100', [['Matching', '/matching'], ['Analyses']]),
        ]);
    }

    /**
     * Assistant de lancement : périmètre, paramètres (lecture seule), prévisualisation, traitement et résultat.
     */
    public function launch(Request $request, MatchingEngine $engine): Response
    {
        $cycle = $request->filled('cycle') ? MatchingCycle::query()->where('reference', $request->query('cycle'))->first() : null;

        return Inertia::render('Matching/Launch', [
            'offers' => $engine->eligible()->map(function (NeedOffer $o) use ($engine): array {
                $r = $engine->results($o);

                return [
                    'id' => $o->id,
                    'label' => "{$o->reference} · {$o->need->site->name} · {$o->line->trade}",
                    'reference' => $o->reference,
                    'site' => $o->need->site->name,
                    'promoter' => $o->need->site->promoter_name,
                    'zone' => NeedInsights::zone($o->need->site->commune),
                    'trade' => $o->line->trade,
                    'urgent' => $o->urgency->isPressing(),
                    'all' => count($r['all']),
                    'excluded' => count($r['ex']),
                    'unverified' => collect($r['all'])->where('p.verified', false)->count(),
                    'partial' => collect($r['all'])->where('p.conflict', 'partiel')->count(),
                ];
            })->values(),
            'config' => MatchingEngine::config(),
            'prefill' => $request->only(['portee', 'cible']),
            'result' => $cycle ? [
                ...$cycle->only(['reference', 'needs', 'recommendations', 'without_profile', 'excluded', 'warnings', 'errors', 'duration']),
                'status' => $cycle->status,
            ] : null,
            'header' => $this->header('Lancer un matching', 'Parcours contrôlé · aucune affectation automatique', [['Matching', '/matching'], ['Lancer un matching']]),
        ]);
    }

    /**
     * Matching d’un besoin (une offre) : synthèse, résultats, comparaison, sélection et budget, profils exclus.
     */
    public function need(Request $request, MatchingEngine $engine, NeedOffer $offer, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::NEED_TABS), 404);
        $offer = $engine->offer($offer->id);
        $presenter = new MatchingPresenter($engine);
        $results = $engine->results($offer);
        $selection = $engine->selection($offer);
        $profile = fn (array $x): array => $presenter->profile($offer, $x);
        $sort = array_key_exists($request->query('tri', ''), self::SORTS) ? $request->query('tri') : 'pertinence';
        $ids = array_filter(explode(',', (string) $request->query('ids', '')));
        $compare = $ids ? array_values(array_filter(array_map(fn (string $id): ?array => collect($results['all'])->firstWhere('id', $id), array_slice($ids, 0, 4)))) : array_slice($results['ok'], 0, 3);
        $base = "/matching/besoins/{$offer->id}";

        return Inertia::render('Matching/Need', [
            'offer' => $presenter->offer($offer),
            'tab' => $tab,
            'counts' => [
                'all' => count($results['all']),
                'ok' => count($results['ok']),
                'excluded' => count($results['ex']),
                'belowExperience' => collect($results['ok'])->filter(fn (array $x): bool => $x['p']['experience'] < (MatchingEngine::EXPERIENCE_YEARS[$offer->line->experience] ?? 0))->count(),
                'belowThreshold' => collect($results['ok'])->filter(fn (array $x): bool => $x['sc']['score'] < min(config('admin.matching.thresholds')))->count(),
            ],
            'best' => $results['ok'] ? $profile($results['ok'][0]) : null,
            'top' => array_map($profile, array_slice($results['ok'], 0, 4)),
            'results' => $tab === 'resultats' ? array_map($profile, self::sort($results['ok'], $sort)) : [],
            'excluded' => in_array($tab, ['', 'exclus'], true) ? array_map($profile, $results['ex']) : [],
            'compare' => array_map($profile, $compare),
            'candidates' => array_map(fn (array $x): array => ['matchId' => $x['id'], 'name' => $x['p']['name']], array_slice($results['ok'], 0, 8)),
            'selection' => $presenter->selection($offer),
            'selectionCount' => count($selection['main']),
            'sorts' => self::SORTS,
            'sort' => $sort,
            'levels' => array_keys(config('admin.matching.thresholds')),
            'config' => MatchingEngine::config(),
            'zone' => NeedInsights::zone($offer->need->site->commune),
            'header' => $this->header('Matching du besoin', '', [['Matching', '/matching'], [$offer->reference, $tab ? $base : null], ...($tab ? [[self::NEED_TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Fiche d’un match : score détaillé, besoin, profil, validation, affectation, résultat réel et historique.
     */
    public function match(MatchingEngine $engine, string $match, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::MATCH_TABS), 404);
        $found = $engine->find($match);
        abort_unless($found, 404, 'Cette recommandation n’existe pas ou n’est plus active.');

        ['offer' => $offer, 'item' => $item] = $found;
        $presenter = new MatchingPresenter($engine);
        $profile = $presenter->profile($offer, $item);
        $assignment = $offer->assignments->first(fn (NeedAssignment $a): bool => $a->worker_name === $item['p']['name'] && $a->source === 'Matching');
        $cycleTime = $offer->last_cycle ? MatchingCycle::query()->where('reference', $offer->last_cycle)->first()?->ran_at->format('H:i') : null;
        $base = "/matching/{$match}";

        return Inertia::render('Matching/Match', [
            'match' => $profile,
            'offer' => $presenter->offer($offer),
            'selection' => $presenter->selection($offer),
            'tab' => $tab,
            'assignment' => $assignment ? [
                'reference' => $assignment->reference, 'status' => $assignment->status->label(), 'score' => $assignment->score, 'warnings' => $assignment->warnings,
                'workerConfirmation' => $assignment->status->value === 'validated' ? 'Confirmée' : 'En attente',
                'promoterConfirmation' => $offer->need->promoter_validated && $assignment->status->value === 'validated' ? 'Confirmée' : 'En attente',
                'pay' => number_format($assignment->daily_cost, 0, ',', ' ').' FCFA/jour',
                'startsOn' => $offer->starts_on->format('Y-m-d'), 'endsOn' => $offer->ends_on->format('Y-m-d'),
                'started' => $assignment->status->value === 'validated' && $offer->need->deposit_paid && $offer->starts_on->lessThanOrEqualTo($engine->today()),
            ] : null,
            'depositPaid' => $offer->need->deposit_paid,
            'recalculations' => MatchingRecalculation::query()->where('match_reference', $match)->latest('recalculated_at')->get()->map(fn (MatchingRecalculation $r): array => [
                ...$r->only(['id', 'old_score', 'new_score', 'reason', 'old_weights', 'new_weights', 'author', 'algorithm']), 'date' => $r->recalculated_at->format('Y-m-d'), 'time' => $r->recalculated_at->format('H:i'),
            ])->all(),
            'audit' => [
                ...MatchingAudit::query()->where(fn ($q) => $q->where('subject', $item['p']['name'])->orWhere('subject', $match)->orWhere('detail', 'like', '%'.$item['p']['name'].'%'))->latest('occurred_at')->get()->map(fn (MatchingAudit $a): array => [
                    'id' => $a->id, 'date' => $a->occurred_at->format('Y-m-d'), 'time' => $a->occurred_at->format('H:i'), 'author' => $a->author, 'action' => $a->action, 'oldValue' => null, 'newValue' => $a->subject, 'comment' => $a->detail, 'origin' => 'Back-office',
                    'category' => ['value' => 'modification', 'label' => 'Modification', 'tone' => 'purple', 'icon' => 'edit'],
                ])->all(),
                ['id' => 0, 'date' => $profile['computedOn'], 'time' => '06:00', 'author' => $profile['computedBy'], 'action' => 'a produit la recommandation', 'oldValue' => null, 'newValue' => "{$profile['score']} / 100", 'comment' => config('admin.matching.algorithm').' · '.config('admin.matching.weights_version'), 'origin' => 'Système', 'category' => ['value' => 'creation', 'label' => 'Création', 'tone' => 'success', 'icon' => 'add_circle']],
            ],
            'cycleTime' => $cycleTime,
            'config' => MatchingEngine::config(),
            'header' => $this->header('Fiche du match', '', [['Matching', '/matching'], [$offer->reference, "/matching/besoins/{$offer->id}"], [strtoupper($match), $tab ? $base : null], ...($tab ? [[self::MATCH_TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * @return array{id: int, reference: string, site: string, trade: string, cost: int}
     */
    private function offerLabel(NeedOffer $o): array
    {
        return ['id' => $o->id, 'reference' => $o->reference, 'site' => $o->need->site->name, 'trade' => $o->line->trade, 'cost' => $o->line->daily_cost];
    }
}
