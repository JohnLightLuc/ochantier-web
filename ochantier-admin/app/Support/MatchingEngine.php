<?php

namespace App\Support;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedAssignmentStatus;
use App\Enums\PromoterStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkerStatus;
use App\Http\Requests\NeedRequest;
use App\Models\MatchingCycle;
use App\Models\MatchingDerogation;
use App\Models\MatchingRejection;
use App\Models\MatchingSelection;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\NeedOffer;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Moteur de Matching : éligibilité des offres, critères bloquants, score pondéré (Paramètres),
 * sélection automatique des meilleurs profils, statuts et budget.
 *
 * Aide à la décision uniquement : aucune affectation n’est créée sans validation humaine.
 * Les résultats sont recalculés à chaque requête (déterministes) ; seules les décisions sont enregistrées.
 */
class MatchingEngine
{
    /**
     * Expérience minimale (années) par niveau demandé.
     */
    public const EXPERIENCE_YEARS = ['Débutant' => 0, 'Junior' => 1, 'Intermédiaire' => 3, 'Expérimenté' => 5, 'Expert' => 8, 'Chef d’équipe' => 10];

    /**
     * Couleur des niveaux de match.
     */
    public const LEVEL_TONES = ['Excellent match' => 'g', 'Très bon match' => 'g', 'Bon match' => 'b', 'Match acceptable' => 'a', 'Match faible' => 'o', 'Non recommandé' => 'r', 'Non éligible' => 'n'];

    /**
     * Couleur des statuts (recommandation, besoin, sélection, cycle, notification).
     */
    public const STATUS_TONES = [
        'Recommandé' => 'b', 'Sélectionné' => 'p', 'En attente de validation' => 'a', 'Correction demandée' => 'o', 'Transmis aux Affectations' => 'b', 'Confirmé par l’ouvrier' => 'g', 'Démarré' => 'g',
        'Refusé (Affectations)' => 'r', 'Rejeté' => 'r', 'Remplaçant principal' => 'n', 'Remplaçant secondaire' => 'n', 'Réserve' => 'n', 'Non lancé' => 'n', 'Prêt à lancer' => 'b',
        'En cours d’analyse' => 'a', 'Résultats disponibles' => 'b', 'Sélection en cours' => 'p', 'À valider' => 'a', 'Partiellement couvert' => 'a', 'Totalement couvert' => 'g',
        'Aucun profil compatible' => 'r', 'À relancer' => 'o', 'Suspendu' => 'r', 'Expiré' => 'o', 'Annulé' => 'n', 'Clôturé' => 'n', 'Terminé' => 'g', 'Partiellement terminé' => 'a',
        'Échec' => 'r', 'Envoyée' => 'g', 'Partiellement envoyée' => 'o', 'Brouillon' => 'n', 'Soumise' => 'a', 'Validée' => 'g', 'Rejetée' => 'r', 'Transmise aux Affectations' => 'b',
    ];

    public const URGENCY_ORDER = ['critical' => 0, 'urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];

    private WorkerPool $pool;

    private CarbonImmutable $today;

    /** @var array<string, mixed> */
    private array $config;

    /** @var Collection<int, NeedOffer> */
    private Collection $offers;

    /** @var array<string, MatchingRejection> */
    private array $rejections;

    /** @var array<string, MatchingDerogation> */
    private array $derogations;

    /** @var Collection<int, MatchingSelection> */
    private Collection $selections;

    /** @var array<string, true> */
    private array $busy;

    /** @var array<int, array<string, mixed>> */
    private array $results = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $auto = null;

    public function __construct()
    {
        $this->pool = new WorkerPool;
        $this->today = Need::referenceDate();
        $this->config = self::config();
        $this->offers = NeedOffer::with('need.site.promoter', 'need.lines', 'line', 'assignments')->whereNull('manual_status')->orderBy('id')->get();
        $this->rejections = MatchingRejection::all()->keyBy(fn (MatchingRejection $r): string => "{$r->need_offer_id}|{$r->candidate}")->all();
        $this->derogations = MatchingDerogation::all()->keyBy(fn (MatchingDerogation $d): string => "{$d->need_offer_id}|{$d->candidate}")->all();
        $this->selections = MatchingSelection::all()->keyBy('need_offer_id');
        $this->busy = NeedAssignment::query()->whereNotIn('status', [NeedAssignmentStatus::Refused, NeedAssignmentStatus::Replaced])->pluck('worker_name')->flip()->map(fn (): bool => true)->all();
    }

    /**
     * Paramètres du moteur (config/admin.php › matching), avec le total des pondérations.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $config = config('admin.matching');
        $config['sum'] = array_sum(array_column($config['weights'], 1));
        $config['ok'] = $config['sum'] === 100;

        return $config;
    }

    public function today(): CarbonImmutable
    {
        return $this->today;
    }

    /**
     * Offres ouvertes (non suspendues, annulées ni clôturées).
     *
     * @return Collection<int, NeedOffer>
     */
    public function offers(): Collection
    {
        return $this->offers;
    }

    public function offer(int $id): ?NeedOffer
    {
        return $this->offers->firstWhere('id', $id) ?? NeedOffer::with('need.site.promoter', 'need.lines', 'line', 'assignments')->find($id);
    }

    /**
     * Offre proposée au Matching : besoin actif, offre ouverte avec des postes restants, date limite non dépassée,
     * chantier actif et promoteur non suspendu.
     */
    public function isEligible(NeedOffer $offer): bool
    {
        $site = $offer->need->site;

        return ! $offer->need->manual_status && ! $offer->manual_status && $offer->isOpen() && $offer->stats()['rest'] > 0
            && $offer->deadline->greaterThanOrEqualTo($this->today)
            && ! in_array($site->status, [SiteStatus::Closed, SiteStatus::Cancelled, SiteStatus::Archived, SiteStatus::Suspended, SiteStatus::Draft], true)
            && $site->promoter?->status !== PromoterStatus::Suspended;
    }

    /**
     * Offres éligibles, de la plus urgente à la moins urgente puis par date de démarrage.
     *
     * @return Collection<int, NeedOffer>
     */
    public function eligible(): Collection
    {
        return $this->offers->filter(fn (NeedOffer $o): bool => $this->isEligible($o))
            ->sortBy([fn (NeedOffer $a, NeedOffer $b): int => self::URGENCY_ORDER[$a->urgency->value] <=> self::URGENCY_ORDER[$b->urgency->value], fn (NeedOffer $a, NeedOffer $b): int => $a->starts_on <=> $b->starts_on])
            ->values();
    }

    public static function matchId(NeedOffer $offer, string $name): string
    {
        return "mt-{$offer->id}-".str_pad((string) (crc32($name) % 10000), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Retrouve un match à partir de sa référence « mt-{offre}-{n} ».
     *
     * @return array{offer: NeedOffer, item: array<string, mixed>}|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^mt-(\d+)-\d{4}$/', $id, $m) || ! ($offer = $this->offer((int) $m[1]))) {
            return null;
        }
        $item = collect($this->results($offer)['all'])->firstWhere('id', $id);

        return $item ? ['offer' => $offer, 'item' => $item] : null;
    }

    /**
     * Profils analysés pour une offre : tous, éligibles (classés par score), exclus et les premiers affichés.
     *
     * @return array{all: array<int, array<string, mixed>>, ok: array<int, array<string, mixed>>, ex: array<int, array<string, mixed>>, top: array<int, array<string, mixed>>}
     */
    public function results(NeedOffer $offer): array
    {
        if (isset($this->results[$offer->id])) {
            return $this->results[$offer->id];
        }

        $all = array_map(function (array $person) use ($offer): array {
            $p = $this->enrich($offer, $person);

            return ['id' => self::matchId($offer, $p['name']), 'p' => $p, 'sc' => $this->evaluate($offer, $p)];
        }, $this->pool->forTrade($offer->line->trade));
        $ok = array_values(array_filter($all, fn (array $x): bool => ! $x['sc']['blocked']));
        usort($ok, fn (array $a, array $b): int => $b['sc']['score'] <=> $a['sc']['score']);

        return $this->results[$offer->id] = [
            'all' => $all,
            'ok' => $ok,
            'ex' => array_values(array_filter($all, fn (array $x): bool => $x['sc']['blocked'])),
            'top' => array_slice($ok, 0, $this->config['limit']),
        ];
    }

    /**
     * Données dérivées d’un profil pour une offre : distance, disponibilité, conflit, fiabilité…
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function enrich(NeedOffer $offer, array $p): array
    {
        $h = crc32($p['name'].$offer->line->trade);
        $site = Site::COORDINATES[$offer->need->site->commune] ?? [5.34, -4.0];
        $from = Site::COORDINATES[$p['commune']] ?? null;
        $km = $from ? max(1.5, round(self::distance($from[0], $from[1], $site[0], $site[1]) * 1.35, 1)) : (float) (12 + $h % 40);
        $real = $p['workerId'] !== null;
        $status = $real ? $p['status'] : ($h % 23 === 0 ? WorkerStatus::Suspended->label() : ($h % 29 === 0 ? WorkerStatus::Archived->label() : $p['status']));
        $availability = $real ? $p['availability'] : ($h % 19 === 0 ? 'En congé' : $p['availability']);
        $details = NeedRequest::TRADE_DETAILS[$offer->line->trade] ?? ['specialties' => [], 'certifications' => []];
        $other = collect($details['specialties'])->first(fn (string $s): bool => $s !== $offer->line->specialty) ?? $offer->line->specialty;

        return [
            ...$p,
            'id' => $p['code'] ?? 'OUV-'.(1000 + $h % 8999),
            'specialty' => $h % 3 ? $offer->line->specialty : $other,
            'status' => $status,
            'availability' => $availability,
            'km' => $km,
            'minutes' => (int) round($km / 22 * 60) + 5,
            'verified' => $status === WorkerStatus::Validated->label(),
            'dossier' => 55 + $h % 46,
            'certs' => $p['certified'] ? array_slice($details['certifications'], 0, 1) : [],
            'availableOn' => $this->today->addDays($availability === 'Disponible' ? $h % 3 : 3 + $h % 12)->format('Y-m-d'),
            'conflict' => $availability === 'En mission' ? ($h % 2 ? 'partiel' : 'total') : '',
            'acceptance' => 70 + $h % 31,
            'presence' => 80 + $h % 21,
            'incidents' => $h % 11 === 0 ? 1 : 0,
            'documentExpired' => $h % 31 === 0,
            'locatedOn' => $this->today->subDays($h % 9)->format('Y-m-d'),
            'locationReliability' => $h % 4 ? 'Élevée' : 'Moyenne',
            'former' => $h % 7 === 0,
            'previous' => $h % 5 === 0,
            'newcomer' => $p['missions'] < 6,
            'lastActivity' => $this->today->subDays($h % 20)->format('Y-m-d'),
        ];
    }

    /**
     * Critères bloquants, avertissements, score pondéré et niveau d’un profil.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function evaluate(NeedOffer $offer, array $p): array
    {
        $c = $this->config;
        $line = $offer->line;
        $date = fn ($d): string => $d->format('d/m/Y');
        $money = fn (int $v): string => number_format($v, 0, ',', ' ');
        $cost = $line->daily_cost;
        $blocking = [];
        $warnings = [];
        $block = function (string $criterion, string $expected, string $found, string $fix, bool $absolute = false) use (&$blocking): void {
            $blocking[] = ['criterion' => $criterion, 'expected' => $expected, 'found' => $found, 'date' => $this->today->format('Y-m-d'), 'fix' => $fix, 'absolute' => $absolute];
        };
        $assignments = $offer->assignments;
        $km = str_replace('.', ',', (string) $p['km']);

        if (in_array($p['status'], ['Suspendu', 'Archivé', 'Désactivé'], true)) {
            $block('Statut du compte', 'Actif', $p['status'], 'Aucune — interdiction absolue', true);
        } elseif ($c['identity_check'] && ! $p['verified']) {
            $block('Identité validée', 'Identité vérifiée', $p['status'], 'Faire valider l’identité dans le module Ouvriers');
        }
        if (in_array($p['availability'], ['En congé', 'Indisponible'], true)) {
            $block('Disponibilité', "Disponible du {$date($offer->starts_on)} au {$date($offer->ends_on)}", $p['availability'], 'Attendre le retour ou mettre à jour la disponibilité');
        }
        if ($p['conflict'] === 'total') {
            $block('Conflit de planning', 'Aucune mission sur la période', 'Mission en cours jusqu’au '.$date($offer->ends_on->copy()->addDays(2)), 'Choisir un autre profil');
        }
        if ($p['km'] > $c['radius_max']) {
            $block('Distance maximale', "≤ {$c['radius_max']} km (Paramètres)", "{$km} km", 'Élargir la zone dans Paramètres');
        }
        if ($cost && $p['cost'] > $cost * (1 + $c['tolerance_max'] / 100)) {
            $block('Rémunération maximale', '≤ '.$money((int) round($cost * (1 + $c['tolerance_max'] / 100))).' FCFA/j', $money($p['cost']).' FCFA/j', 'Ajuster le budget dans Besoins');
        }
        if ($p['documentExpired']) {
            $block('Document obligatoire', 'Pièce d’identité valide', 'Expirée', 'Mettre à jour le document dans Ouvriers');
        }
        if ($p['disputes']) {
            $block('Litige bloquant', 'Aucun litige actif', "{$p['disputes']} litige actif", 'Aucune — interdiction absolue', true);
        }
        if ($assignments->contains(fn (NeedAssignment $a): bool => $a->worker_name === $p['name'] && $a->status->isSelected())) {
            $block('Déjà retenu', 'Non retenu sur ce poste', 'Déjà transmis aux Affectations', 'Aucune');
        }
        if ($assignments->contains(fn (NeedAssignment $a): bool => $a->worker_name === $p['name'] && $a->status === NeedAssignmentStatus::Refused)) {
            $block('Refus de la mission', 'Aucun refus valide', 'A refusé cette mission', 'Aucune');
        }
        if ($offer->need->manual_status || ! $offer->isOpen()) {
            $block('Besoin actif', 'Besoin ouvert', $offer->need->manual_status?->label() ?? $offer->status(), 'Actualiser le besoin');
        }

        if ($p['conflict'] === 'partiel') {
            $warnings[] = 'Chevauchement partiel de planning (2 jours)';
        }
        if ($p['availability'] === 'Préaffecté') {
            $warnings[] = 'Disponibilité non confirmée';
        }
        if ($p['km'] > $c['radius'] && $p['km'] <= $c['radius_max']) {
            $warnings[] = "Au-delà du rayon prioritaire ({$c['radius']} km)";
        }
        if ($cost && $p['cost'] > $cost && $p['cost'] <= $cost * (1 + $c['tolerance_max'] / 100)) {
            $warnings[] = 'Coût supérieur au budget ('.round(($p['cost'] / $cost - 1) * 100).' %)';
        }
        if (($offer->need->details['negotiation'] ?? null) === 'Négociable') {
            $warnings[] = 'Rémunération négociable : montant non définitif';
        }

        $blocked = $blocking !== [];
        $derogation = $this->derogations["{$offer->id}|{$p['key']}"] ?? null;
        if ($blocked && $derogation && ! collect($blocking)->contains('absolute', true)) {
            $blocked = false;
            array_unshift($warnings, "Dérogation accordée : {$derogation->justification}");
        }

        $need = self::EXPERIENCE_YEARS[$line->experience] ?? 0;
        $years = fn (int $n): string => $n.' an'.($n > 1 ? 's' : '');
        $source = $p['workerId'] ? 'Module Ouvriers' : 'Module Ouvriers (profil déclaré)';
        $sameSpecialty = $p['specialty'] === $line->specialty;
        $values = [
            'metier' => [$sameSpecialty ? ($p['verified'] || ! $c['identity_check'] ? 100 : 80) : 70, 'Métier · spécialité', "{$line->trade} · {$line->specialty}", "{$p['trade']} · {$p['specialty']}".($p['verified'] ? ' (vérifiée)' : ' (déclarée)'), $sameSpecialty ? 'Correspondance exacte'.($p['verified'] ? '' : ', compétence non vérifiée') : 'Spécialité secondaire'],
            'dispo' => [$p['conflict'] === 'total' ? 0 : ($p['availability'] === 'Disponible' ? 100 : ($p['availability'] === 'Préaffecté' ? 70 : ($p['conflict'] === 'partiel' ? 55 : 30))), 'Planning et disponibilité', "Du {$date($offer->starts_on)} au {$date($offer->ends_on)}", "{$p['availability']} · dès le ".date('d/m/Y', strtotime($p['availableOn'])), $p['conflict'] ? "Conflit {$p['conflict']}" : 'Aucun conflit détecté'],
            'exp' => [$need ? min(100, (int) round($p['experience'] / $need * 100)) : 100, 'Années d’expérience', "{$line->experience} ({$years($need)} min.)", $years($p['experience']), $p['experience'] >= $need ? 'Expérience suffisante' : 'Expérience inférieure au niveau demandé'],
            'prox' => [max(0, (int) round(100 - max(0, $p['km'] - 3) / $c['radius_max'] * 100)), 'Distance routière estimée', "≤ {$c['radius']} km prioritaire", "{$km} km · {$p['minutes']} min", "Depuis {$p['commune']} · fiabilité ".mb_strtolower($p['locationReliability'])],
            'cout' => [! $cost || $p['cost'] <= $cost ? 100 : max(0, (int) round(100 - ($p['cost'] - $cost) / $cost * 400)), 'Coût journalier', '≤ '.$money($cost).' FCFA/j', $money($p['cost']).' FCFA/j', $p['cost'] <= $cost ? 'Dans le budget' : 'Au-dessus du budget'],
            'qual' => [$p['dossier'], 'Complétude du dossier', '100 %', "{$p['dossier']} %", $p['verified'] ? 'Profil vérifié' : 'Profil non vérifié'],
            'note' => [(int) round($p['rating'] / 5 * 70 + $p['acceptance'] * 0.3), 'Note moyenne et fiabilité', '≥ 4 / 5', str_replace('.', ',', (string) $p['rating'])." / 5 · {$p['acceptance']} % d’acceptation", $p['rating'] >= 4 ? 'Bonne réputation' : 'Réputation moyenne'],
            'hist' => [min(100, (int) round(min(100, $p['missions'] * 4) * $p['presence'] / 100)), 'Missions et présence', '≥ 20 missions', "{$p['missions']} mission(s) · {$p['presence']} % de présence".($p['incidents'] ? ' · 1 incident' : ''), $p['incidents'] ? 'Un incident signalé' : 'Engagements respectés'],
        ];

        $criteria = [];
        foreach ($c['weights'] as $key => [$label, $weight]) {
            [$n, $data, $expected, $value, $why] = $values[$key];
            $criteria[] = [
                'key' => $key, 'label' => $label, 'weight' => $weight, 'n' => $n, 'data' => $data, 'expected' => $expected, 'value' => $value, 'why' => $why,
                'contribution' => round($n * $weight / 100, 2), 'level' => $n >= 80 ? 'Forte' : ($n >= 55 ? 'Moyenne' : 'Faible'),
                'source' => $key === 'prox' ? 'Chantiers + localisation habituelle' : ($key === 'cout' ? 'Besoins (budget) + Ouvriers (tarif)' : $source),
                'updatedOn' => $key === 'prox' ? $p['locatedOn'] : $p['lastActivity'],
            ];
        }
        $raw = (int) round(array_sum(array_map(fn (array $x): float => $x['n'] * $x['weight'], $criteria)) / 100);
        $score = $blocked ? 0 : $raw;
        $level = 'Non recommandé';
        foreach ($c['thresholds'] as $label => $threshold) {
            if ($score >= $threshold) {
                $level = $label;
                break;
            }
        }

        return [
            'criteria' => $criteria,
            'score' => $score,
            'raw' => $raw,
            'level' => $blocked ? 'Non éligible' : $level,
            'blocked' => $blocked,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'date' => ($offer->last_matched_on ?? $this->today)->format('Y-m-d'),
            'by' => $offer->last_matched_by ?? 'Système',
        ];
    }

    /**
     * Sélection automatique : les meilleurs profils du métier, un ouvrier par poste, sans réutiliser un même
     * ouvrier sur deux offres ni un ouvrier déjà retenu ailleurs ; les offres les plus urgentes servent d’abord.
     *
     * @return array<int, array{pick: array<int, array<string, mixed>>, alt: array<int, array<string, mixed>>}>
     */
    public function autoPicks(): array
    {
        if ($this->auto !== null) {
            return $this->auto;
        }

        $used = [];
        $map = [];
        foreach ($this->eligible() as $offer) {
            $pool = $this->config['ok'] ? array_values(array_filter($this->results($offer)['ok'], fn (array $x): bool => ! isset($this->busy[$x['p']['name']]))) : [];
            $rest = $offer->stats()['rest'];
            $pick = [];
            foreach ($pool as $x) {
                if (count($pick) < $rest && ! isset($used[$x['p']['name']])) {
                    $pick[] = $x;
                    $used[$x['p']['name']] = true;
                }
            }
            $map[$offer->id] = ['pick' => $pick, 'pool' => $pool];
        }
        foreach ($map as $id => $m) {
            $map[$id]['alt'] = array_slice(array_values(array_filter($m['pool'], fn (array $x): bool => ! isset($used[$x['p']['name']]))), 0, 3);
            unset($map[$id]['pool']);
        }

        return $this->auto = $map;
    }

    /**
     * Remplaçants proposés pour un poste libéré : profils éligibles du même métier, ni retenus ailleurs ni déjà sur l’offre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function replacements(NeedOffer $offer, int $limit = 3): array
    {
        $taken = $offer->assignments->pluck('worker_name')->flip();
        $pool = array_filter($this->results($offer)['ok'], fn (array $x): bool => ! isset($this->busy[$x['p']['name']]) && ! $taken->has($x['p']['name']));

        return array_slice(array_values($pool), 0, $limit);
    }

    /**
     * Ajoute un profil aux ouvriers trouvés d’une offre (statut « À contacter ») et l’inscrit au journal du besoin.
     *
     * @param  array<string, mixed>  $item
     */
    public function assign(NeedOffer $offer, array $item, string $source = 'Matching', ?string $replaces = null): NeedAssignment
    {
        $p = $item['p'];
        $h = crc32($p['name']);
        $assignment = $offer->assignments()->create([
            'need_id' => $offer->need_id,
            'worker_id' => $p['workerId'],
            'reference' => NeedAssignment::nextReference(),
            'worker_name' => $p['name'],
            'trade' => $p['trade'],
            'commune' => $p['commune'],
            'phone' => '07 '.(10 + $h % 89).' '.(10 + ($h >> 3) % 89).' '.(10 + ($h >> 6) % 89).' '.(10 + ($h >> 9) % 89),
            'daily_cost' => $p['cost'],
            'status' => NeedAssignmentStatus::ToContact,
            'source' => $source,
            'selected_on' => $this->today,
            'score' => $item['sc']['score'],
            'warnings' => implode(' · ', $item['sc']['warnings']) ?: null,
            'match_reference' => $item['id'],
            'replaces' => $replaces,
        ]);
        if ($offer->relationLoaded('assignments')) {
            $offer->assignments->push($assignment);
        }
        $this->busy[$p['name']] = true;
        $action = $replaces ? 'a proposé un ouvrier de remplacement' : ($source === 'Manuel' ? 'a ajouté un ouvrier manuellement' : 'a sélectionné un ouvrier (matching)');
        $offer->need->logActivity(NeedActivityCategory::Assignment, $action, $replaces, 'À contacter', "{$p['name']} · {$p['trade']}");

        return $assignment;
    }

    /**
     * Sélection d’une offre : celle enregistrée, ou à défaut les profils proposés automatiquement.
     *
     * @return array{main: array<int, string>, replacements: array<string, string>, status: string, author: string, updatedOn: string, note: string|null, persisted: bool}
     */
    public function selection(NeedOffer $offer): array
    {
        $record = $this->selections->get($offer->id);
        if ($record) {
            return ['main' => $record->main, 'replacements' => $record->replacements, 'status' => $record->status, 'author' => $record->author, 'updatedOn' => $record->updated_on->format('Y-m-d'), 'note' => $record->note, 'persisted' => true];
        }

        $keys = array_map(fn (array $x): string => $x['p']['key'], $this->autoPicks()[$offer->id]['pick'] ?? []);

        return ['main' => $keys, 'replacements' => [], 'status' => 'Sélection en cours', 'author' => 'Matching automatique', 'updatedOn' => $this->today->format('Y-m-d'), 'note' => null, 'persisted' => false];
    }

    /**
     * Enregistre la sélection d’une offre (créée à partir de la sélection automatique si besoin).
     *
     * @param  array<string, mixed>  $changes
     */
    public function saveSelection(NeedOffer $offer, array $changes): MatchingSelection
    {
        $current = $this->selection($offer);
        $record = MatchingSelection::updateOrCreate(['need_offer_id' => $offer->id], [
            'main' => array_values($changes['main'] ?? $current['main']),
            'replacements' => $changes['replacements'] ?? $current['replacements'],
            'status' => $changes['status'] ?? $current['status'],
            'author' => auth()->user()?->name ?? 'Système',
            'updated_on' => $this->today,
            'note' => array_key_exists('note', $changes) ? $changes['note'] : $current['note'],
        ]);
        $this->selections->put($offer->id, $record);

        return $record;
    }

    /**
     * Profil d’une offre à partir de sa clé.
     *
     * @return array<string, mixed>|null
     */
    public function item(NeedOffer $offer, string $key): ?array
    {
        return collect($this->results($offer)['all'])->first(fn (array $x): bool => $x['p']['key'] === $key);
    }

    public function isRejected(NeedOffer $offer, string $key): bool
    {
        return isset($this->rejections["{$offer->id}|{$key}"]);
    }

    public function rejection(NeedOffer $offer, string $key): ?MatchingRejection
    {
        return $this->rejections["{$offer->id}|{$key}"] ?? null;
    }

    public function derogation(NeedOffer $offer, string $key): ?MatchingDerogation
    {
        return $this->derogations["{$offer->id}|{$key}"] ?? null;
    }

    /**
     * Statut d’un profil pour une offre : transmis, confirmé, démarré, rejeté, remplaçant, sélectionné ou recommandé.
     */
    public function recommendationStatus(NeedOffer $offer, array $item): string
    {
        $assignment = $offer->assignments->first(fn (NeedAssignment $a): bool => $a->worker_name === $item['p']['name'] && $a->source === 'Matching');
        if ($assignment) {
            return match ($assignment->status) {
                NeedAssignmentStatus::Validated => $offer->need->deposit_paid && $offer->starts_on->lessThanOrEqualTo($this->today) ? 'Démarré' : 'Confirmé par l’ouvrier',
                NeedAssignmentStatus::Refused => 'Refusé (Affectations)',
                default => 'Transmis aux Affectations',
            };
        }
        if ($this->isRejected($offer, $item['p']['key'])) {
            return 'Rejeté';
        }
        $selection = $this->selection($offer);
        if (isset($selection['replacements'][$item['p']['key']])) {
            return $selection['replacements'][$item['p']['key']];
        }
        if (in_array($item['p']['key'], $selection['main'], true)) {
            return $selection['status'] === 'En attente de validation' ? 'En attente de validation' : 'Sélectionné';
        }

        return 'Recommandé';
    }

    /**
     * Statut du matching d’une offre.
     */
    public function offerStatus(NeedOffer $offer): string
    {
        $need = $offer->need;
        if ($need->manual_status) {
            return in_array($need->manual_status->label(), ['Suspendu', 'Annulé'], true) ? $need->manual_status->label() : 'Clôturé';
        }
        if ($offer->status() === NeedOffer::EXPIRED || $offer->deadline->lessThan($this->today)) {
            return 'Expiré';
        }
        if ($offer->stats()['rest'] === 0) {
            return 'Totalement couvert';
        }
        if (! $offer->last_matched_on) {
            return $offer->published_on->equalTo($this->today) ? 'Non lancé' : 'Prêt à lancer';
        }
        if (! $this->results($offer)['ok']) {
            return 'Aucun profil compatible';
        }
        $selection = $this->selection($offer);
        if ($selection['status'] === 'En attente de validation' && $selection['main']) {
            return 'À valider';
        }
        if ($selection['main']) {
            return 'Sélection en cours';
        }
        if ($offer->assignments->contains(fn (NeedAssignment $a): bool => $a->source === 'Matching' && $a->selected_on->greaterThanOrEqualTo($offer->last_matched_on))) {
            return 'Partiellement couvert';
        }

        return $this->isObsolete($offer) ? 'À relancer' : 'Résultats disponibles';
    }

    public function isObsolete(NeedOffer $offer): bool
    {
        return $offer->last_matched_on && (int) $offer->last_matched_on->diffInDays($this->today) > $this->config['obsolete_days'];
    }

    /**
     * Budget estimé d’une sélection : coût sur les jours ouvrés (indemnité de transport comprise) face au budget disponible.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function budget(NeedOffer $offer, array $keys): array
    {
        $days = max(1, (int) round(($offer->starts_on->diffInDays($offer->ends_on) + 1) * 6 / 7));
        $allowance = str_contains((string) $offer->benefits, 'Transport') ? 1000 : 0;
        $rest = $offer->stats()['rest'];
        $per = collect($keys)->map(fn (string $k): ?array => $this->item($offer, $k))->filter()->map(fn (array $x): array => ['key' => $x['p']['key'], 'name' => $x['p']['name'], 'cost' => $x['p']['cost'], 'total' => ($x['p']['cost'] + $allowance) * $days])->values()->all();
        $total = array_sum(array_column($per, 'total'));
        $available = (int) round(($offer->line->daily_cost + $allowance) * $days * $rest * (1 + $this->config['tolerance'] / 100));
        $payType = $offer->line->pay_type;

        return [
            'days' => $days, 'allowance' => $allowance, 'per' => $per, 'total' => $total, 'available' => $available, 'gap' => $available - $total,
            'percent' => $available ? (int) round($total / $available * 100) : 0, 'over' => $total > $available,
            'negotiable' => ($offer->need->details['negotiation'] ?? null) === 'Négociable',
            'note' => in_array($payType, ['Forfaitaire', 'Contractuel', 'Par étape', 'Par livrable'], true) ? "Estimation sur {$days} jours ouvrés (".mb_strtolower($payType).')' : "{$days} jours ouvrés · ".mb_strtolower($payType),
        ];
    }

    /**
     * Indicateurs de réussite : recommandations, sélections, validations, confirmations, démarrages.
     *
     * @return array<string, int>
     */
    public function success(): array
    {
        $assignments = NeedAssignment::with('offer.need')->where('source', 'Matching')->get();
        $confirmed = $assignments->where('status', NeedAssignmentStatus::Validated);
        $started = $confirmed->filter(fn (NeedAssignment $a): bool => $a->offer->need->deposit_paid && $a->offer->starts_on->lessThanOrEqualTo($this->today));
        $cycles = MatchingCycle::query()->where('status', '!=', 'Annulé')->get();
        $sum = fn (string $k): int => (int) $cycles->sum($k);
        $rec = $sum('recommendations');
        $proposals = $sum('assignments') + $assignments->count();
        $conf = $sum('assignments') - $sum('failures') + $confirmed->count();
        $start = $sum('successes') + $started->count();

        return [
            'recommendations' => $rec, 'selections' => $sum('selections'), 'validations' => $sum('validations'), 'proposals' => $proposals, 'confirmed' => $conf, 'started' => $start, 'failures' => $sum('failures'),
            'rate' => $conf ? (int) round($start / $conf * 100) : 0,
            'acceptance' => $rec ? (int) round($sum('validations') / $rec * 100) : 0,
            'confirmation' => $proposals ? (int) round($conf / $proposals * 100) : 0,
            'start' => $conf ? (int) round($start / $conf * 100) : 0,
            'completion' => $start ? (int) round(($start - (int) round($sum('failures') / 2)) / $start * 100) : 0,
        ];
    }

    /**
     * Distance à vol d’oiseau (km).
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = fn (float $x): float => $x * M_PI / 180;
        $x = sin($r($lat2 - $lat1) / 2) ** 2 + cos($r($lat1)) * cos($r($lat2)) * sin($r($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371 * asin(sqrt($x));
    }
}
