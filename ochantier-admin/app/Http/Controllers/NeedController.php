<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedDocumentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use App\Enums\PromoterStatus;
use App\Enums\WorkerStatus;
use App\Http\Requests\NeedRequest;
use App\Http\Resources\NeedActivityResource;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\NeedDocument;
use App\Models\NeedLine;
use App\Models\NeedOffer;
use App\Models\Promoter;
use App\Models\Site;
use App\Models\Worker;
use App\Support\NeedInsights;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NeedController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    public const TABS = ['' => 'Vue d’ensemble', 'metiers' => 'Métiers et effectifs', 'affectations' => 'Ouvriers trouvés', 'documents' => 'Documents', 'historique' => 'Activité et historique'];

    /**
     * Colonnes et critères de tri : clé d’URL → valeur de la ligne.
     */
    private const SORTABLE = [
        'reference' => 'reference', 'chantier' => 'site', 'type_chantier' => 'siteType', 'promoteur' => 'promoter', 'commune' => 'commune', 'metier' => 'mainTrade',
        'metiers' => 'tradesCount', 'selection' => 'selected', 'manquants' => 'rest', 'couverture' => 'percent', 'demarrage' => 'startsOn', 'limite' => 'deadline',
        'duree' => 'duration', 'urgence' => 'urgency', 'type' => 'type', 'statut' => 'status', 'statut_couverture' => 'coverage', 'creation' => 'createdOn',
        'postes' => 'need', 'remuneration' => 'cost', 'activite' => 'updatedOn',
    ];

    private const URGENCY_ORDER = ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3, 'critical' => 4];

    /**
     * Filtres de la liste : clé d’URL → [libellé, avancé (« Plus de filtres »)].
     */
    private const FILTERS = [
        'statut' => ['Statut', false], 'promoteur' => ['Promoteur', false], 'chantier' => ['Chantier', false], 'type_chantier' => ['Type de chantier', false],
        'metier' => ['Métier', false], 'urgence' => ['Urgence', false], 'couverture' => ['Couverture', false],
        'specialite' => ['Spécialité', true], 'type' => ['Type de besoin', true], 'ville' => ['Ville', true], 'commune' => ['Commune', true], 'zone' => ['Zone', true],
        'creation' => ['Date de création', true], 'demarrage' => ['Date de démarrage', true], 'remuneration' => ['Type de rémunération', true],
        'responsable' => ['Responsable administratif', true], 'source' => ['Source de création', true], 'anomalie' => ['Anomalies', true],
    ];

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'onglet', 'rapide', 'alerte', 'tri', 'sens', 'page', 'vue', 'plus', ...array_keys(self::FILTERS)]);
        $tab = array_key_exists($filters['onglet'] ?? '', NeedInsights::TABS) ? $filters['onglet'] : 'attente';
        $insights = new NeedInsights;
        $needs = $this->needs();

        $rows = $this->sorted($this->filtered($needs, $filters, $tab, $insights)->map(fn (Need $n): array => [...$insights->row($n), 'cost' => $n->lines->max('daily_cost'), 'updatedOn' => $n->updated_on->format('Y-m-d')]), $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page);
        $values = $insights->quickValues($needs);
        $view = ($filters['vue'] ?? '') === 'cartes' ? 'cartes' : 'tableau';

        return Inertia::render('Needs/Index', [
            'needs' => [
                'data' => $paginator->items(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'tab' => $tab,
            'tabs' => collect(NeedInsights::TABS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'count' => $needs->filter(fn (Need $n): bool => $insights->inTab($n, $key))->count()])->values()->all(),
            'filters' => array_filter($filters),
            'view' => $view,
            'filterDefs' => $this->filterDefs($needs, $tab),
            'quick' => collect(NeedInsights::QUICK)->map(fn (array $q, string $key): array => ['key' => $key, 'label' => $q[0], 'icon' => $q[1], 'tone' => $q[2], 'value' => $values[$key]])->values()->all(),
            'stats' => ['delay' => $values['delai'], 'pendingNeed' => (int) $values['pendingNeed'], 'pendingSelected' => (int) $values['pendingSelected']],
            'todo' => collect(NeedInsights::BOTTOM)->map(fn (string $label, string $key): int => $needs->filter(fn (Need $n): bool => $insights->matchesBottom($n, $key))->count())->all(),
            'cards' => $view === 'cartes' ? $this->filtered($needs, $filters, $tab, $insights)->map(fn (Need $n): array => $insights->card($n))->values()->all() : [],
        ]);
    }

    /**
     * @return EloquentCollection<int, Need>
     */
    private function needs(): EloquentCollection
    {
        return Need::with(NeedInsights::RELATIONS)->orderBy('id')->get();
    }

    /**
     * Raccourci ou alerte du bas de liste prioritaires sur l’onglet, puis recherche et filtres.
     *
     * @param  EloquentCollection<int, Need>  $needs
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Need>
     */
    private function filtered(EloquentCollection $needs, array $filters, string $tab, NeedInsights $insights): Collection
    {
        $quick = array_key_exists($filters['rapide'] ?? '', NeedInsights::QUICK) ? $filters['rapide'] : null;
        $bottom = array_key_exists($filters['alerte'] ?? '', NeedInsights::BOTTOM) ? $filters['alerte'] : null;
        $q = Str::lower(trim((string) ($filters['q'] ?? '')));

        return $needs->filter(function (Need $n) use ($filters, $tab, $insights, $quick, $bottom, $q): bool {
            if ($quick && ! $insights->matchesQuick($n, $quick)) {
                return false;
            }
            if ($bottom && ! $insights->matchesBottom($n, $bottom)) {
                return false;
            }
            if (! $quick && ! $bottom && ! $insights->inTab($n, $tab)) {
                return false;
            }
            if ($q !== '' && ! Str::contains(Str::lower(implode(' ', [$n->reference, $n->site->name, $n->site->promoter_name, $n->site->commune, $n->site->city, $n->district, $n->site->type, $n->lines->map(fn (NeedLine $l): string => "{$l->trade} {$l->specialty}")->implode(' ')])), $q)) {
                return false;
            }

            foreach (array_keys(self::FILTERS) as $key) {
                if (! empty($filters[$key]) && ! $this->matchesFilter($n, $key, (string) $filters[$key])) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function matchesFilter(Need $n, string $key, string $value): bool
    {
        $today = Need::referenceDate();
        $since = (int) $n->created_on->diffInDays($today);
        $until = (int) $today->diffInDays($n->starts_on, false);

        return match ($key) {
            'statut' => $n->manual_status?->value === $value,
            'promoteur' => $n->site->promoter_name === $value,
            'chantier' => $n->site->name === $value,
            'type_chantier' => $n->site->type === $value,
            'metier' => $n->lines->contains('trade', $value),
            'urgence' => $n->urgency->value === $value,
            'couverture' => $n->coverage()['label'] === $value,
            'specialite' => $n->lines->contains('specialty', $value),
            'type' => $n->type === $value,
            'ville' => $n->site->city === $value,
            'commune' => $n->site->commune === $value,
            'zone' => NeedInsights::zone($n->site->commune) === $value,
            'creation' => match ($value) {
                '7' => $since <= 7,
                '30' => $since <= 30,
                default => $since > 30,
            },
            'demarrage' => match ($value) {
                'demarre' => $until < 0,
                '7' => $until >= 0 && $until <= 7,
                '30' => $until >= 0 && $until <= 30,
                default => $until > 30,
            },
            'remuneration' => $n->lines->contains('pay_type', $value),
            'responsable' => $n->admin === $value,
            'source' => $n->source === $value,
            'anomalie' => collect((new NeedInsights)->alerts($n))->contains(fn (array $a): bool => in_array($a['key'], ['anomalie', 'dates'], true)) === ($value === 'avec'),
            default => true,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sorted(Collection $rows, array $filters): Collection
    {
        $field = self::SORTABLE[$filters['tri'] ?? ''] ?? null;
        if (! $field) {
            return $rows->values();
        }

        $value = fn (array $row): int|string => match ($field) {
            'urgency' => self::URGENCY_ORDER[$row['urgency']['value']],
            'status', 'coverage' => Str::ascii($row[$field]['label']),
            'deadline' => $row['deadline'] ?? '9999',
            default => is_string($row[$field]) ? Str::lower(Str::ascii($row[$field])) : (int) $row[$field],
        };

        return $rows->sortBy($value, SORT_NATURAL, ($filters['sens'] ?? 'asc') === 'desc')->values();
    }

    /**
     * Définition des filtres (options calculées sur les besoins existants) ; le statut ne concerne que l’onglet des besoins suspendus et clôturés.
     *
     * @param  EloquentCollection<int, Need>  $needs
     * @return array<int, array{key: string, label: string, advanced: bool, options: array<int, array{value: string, label: string}>}>
     */
    private function filterDefs(EloquentCollection $needs, string $tab): array
    {
        $option = fn (string $value, ?string $label = null): array => ['value' => $value, 'label' => $label ?? $value];
        $unique = fn (Collection $values): array => $values->filter()->unique()->sort(fn (string $a, string $b): int => strcoll(Str::ascii($a), Str::ascii($b)))->values()->map(fn (string $v): array => $option($v))->all();
        $lines = $needs->flatMap->lines;
        $options = [
            'statut' => array_map(fn (NeedManualStatus $s): array => $option($s->value, $s->label()), NeedManualStatus::cases()),
            'promoteur' => $unique($needs->map(fn (Need $n): string => $n->site->promoter_name)),
            'chantier' => $unique($needs->map(fn (Need $n): string => $n->site->name)),
            'type_chantier' => $unique(Site::query()->pluck('type')),
            'metier' => $unique($lines->pluck('trade')),
            'urgence' => array_map(fn (NeedUrgency $u): array => $option($u->value, $u->label()), NeedUrgency::cases()),
            'couverture' => array_map(fn (string $l): array => $option($l), ['Non couvert', 'Faiblement couvert', 'Partiellement couvert', 'Presque couvert', 'Totalement couvert', 'Surcouvert']),
            'specialite' => $unique($lines->pluck('specialty')),
            'type' => array_map(fn (string $t): array => $option($t), NeedRequest::TYPES),
            'ville' => $unique($needs->map(fn (Need $n): string => $n->site->city)),
            'commune' => $unique($needs->map(fn (Need $n): string => $n->site->commune)),
            'zone' => array_map(fn (string $z): array => $option($z), ['Abidjan Centre', 'Abidjan Est', 'Abidjan Nord-Ouest', 'Abidjan Sud', 'Intérieur du pays']),
            'creation' => [$option('7', '7 derniers jours'), $option('30', '30 derniers jours'), $option('plus-30', 'Plus de 30 jours')],
            'demarrage' => [$option('demarre', 'Déjà démarré'), $option('7', 'Dans les 7 jours'), $option('30', 'Dans les 30 jours'), $option('plus-30', 'Au-delà de 30 jours')],
            'remuneration' => array_map(fn (string $p): array => $option($p), NeedRequest::PAY_TYPES),
            'responsable' => array_map(fn (string $a): array => $option($a), NeedRequest::ADMINS),
            'source' => array_map(fn (string $s): array => $option($s), NeedRequest::SOURCES),
            'anomalie' => [$option('avec', 'Avec anomalie'), $option('sans', 'Sans anomalie')],
        ];

        return collect(self::FILTERS)
            ->reject(fn (array $def, string $key): bool => $key === 'statut' && $tab !== 'suspendus')
            ->map(fn (array $def, string $key): array => ['key' => $key, 'label' => $def[0], 'advanced' => $def[1], 'options' => $options[$key]])
            ->values()->all();
    }

    /**
     * Export CSV de l’onglet courant (avec ses filtres).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['q', 'onglet', 'rapide', 'alerte', ...array_keys(self::FILTERS)]);
        $tab = array_key_exists($filters['onglet'] ?? '', NeedInsights::TABS) ? $filters['onglet'] : 'attente';
        $insights = new NeedInsights;
        $ids = array_filter(explode(',', (string) $request->query('ids')));
        $rows = $this->filtered($this->needs(), $filters, $tab, $insights)
            ->when($ids, fn (Collection $c) => $c->whereIn('id', $ids))
            ->map(fn (Need $n): array => $insights->row($n));
        $date = fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

        return response()->streamDownload(function () use ($rows, $date): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Référence', 'Chantier', 'Type de chantier', 'Promoteur', 'Commune / ville', 'Métier principal', 'Métiers', 'Sélectionnés / recherchés', 'Manquants', 'Couverture', 'Démarrage', 'Date limite', 'Durée', 'Urgence', 'Type de besoin', 'Statut', 'Statut de couverture', 'Création'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['reference'], $r['site'], $r['siteType'], $r['promoter'], "{$r['commune']}, {$r['city']}", $r['mainTrade'], $r['tradesCount'], "{$r['selected']} / {$r['need']}", $r['rest'], "{$r['percent']} %", $date($r['startsOn']), $date($r['deadline']), "{$r['duration']} j", $r['urgency']['label'], $r['type'], $r['status']['label'], $r['coverage']['label'], $date($r['createdOn'])], ';');
            }
            fclose($out);
        }, "besoins-{$tab}-".Need::referenceDate()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): Response
    {
        return Inertia::render('Needs/Form', [
            'need' => null,
            'reference' => 'BES-2026-'.Need::nextNumber(),
            'values' => null,
            'options' => $this->formOptions(),
            'header' => $this->header('Nouveau besoin', 'Besoin en main-d’œuvre rattaché à un chantier existant', [['Besoins', '/besoins'], ['Nouveau besoin']]),
        ]);
    }

    public function store(NeedRequest $request): RedirectResponse
    {
        $site = Site::query()->where('slug', $request->validated('site'))->firstOrFail();

        $need = DB::transaction(function () use ($request, $site): Need {
            $number = Need::nextNumber();
            $need = Need::create([
                ...$request->needData($site),
                'slug' => "bes-2026-{$number}",
                'reference' => "BES-2026-{$number}",
                'anomalies' => [],
                'created_on' => Need::referenceDate(),
                'updated_on' => Need::referenceDate(),
            ]);
            foreach ($request->linesData() as $line) {
                $need->lines()->create($line);
            }
            $this->publishOffers($need, $request);
            $this->saveUploads($request, $need);
            $need->logActivity(NeedActivityCategory::Creation, 'a créé le besoin · offres publiées automatiquement', null, 'Publié', $need->source);

            return $need;
        });

        Inertia::flash('toast', 'Besoin enregistré · '.$need->lines()->count().' offre(s) publiée(s), ouvriers ciblés notifiés.');

        return to_route('needs.show', $need);
    }

    public function edit(Need $need): Response
    {
        abort_if((bool) $need->manual_status, 403, 'Besoin '.mb_strtolower((string) $need->manual_status?->label()).' : modification impossible.');
        $need->load('site', 'lines', 'offers');

        return Inertia::render('Needs/Form', [
            'need' => ['slug' => $need->slug, 'reference' => $need->reference],
            'reference' => $need->reference,
            'values' => $this->formValues($need),
            'options' => $this->formOptions(),
            'header' => $this->header("Modifier le besoin {$need->reference}", 'Besoin en main-d’œuvre rattaché à un chantier existant', [['Besoins', '/besoins'], [$need->reference, "/besoins/{$need->slug}"], ['Modifier']]),
        ]);
    }

    public function update(NeedRequest $request, Need $need): RedirectResponse
    {
        abort_if((bool) $need->manual_status, 403);
        $site = Site::query()->where('slug', $request->validated('site'))->firstOrFail();
        $need->load('lines.offer.assignments');
        $lines = $request->linesData();

        $removed = $need->lines->slice(count($lines))->first(fn (NeedLine $l): bool => (bool) $l->offer?->assignments->contains(fn (NeedAssignment $a): bool => $a->status->isSelected()));
        if ($removed) {
            throw ValidationException::withMessages(['lines' => "La ligne {$removed->trade} a déjà des ouvriers trouvés : elle ne peut pas être supprimée."]);
        }

        DB::transaction(function () use ($request, $need, $site, $lines): void {
            $need->update($request->needData($site));
            foreach ($lines as $i => $data) {
                $line = $need->lines[$i] ?? null;
                $line ? $line->update($data) : $need->lines()->create($data);
            }
            $need->lines->slice(count($lines))->each->delete();
            $this->publishOffers($need->fresh(), $request);
            $this->saveUploads($request, $need);
            $need->logActivity(NeedActivityCategory::Update, 'a modifié le besoin', null, null, $need->source);
        });

        Inertia::flash('toast', 'Besoin mis à jour · offres actualisées.');

        return to_route('needs.show', $need);
    }

    /**
     * Une offre par ligne : les offres en cours suivent le besoin, les nouvelles lignes sont publiées automatiquement.
     */
    private function publishOffers(Need $need, NeedRequest $request): void
    {
        $need->load('lines.offer', 'site');
        $deadline = $request->validated('deadline');
        $benefits = array_filter([
            $request->validated('transport') ? 'Transport '.$request->validated('transport').' FCFA/jour' : null,
            $request->validated('meals') ? 'Repas '.$request->validated('meals').' FCFA/jour' : null,
            $request->input('benefits'),
        ]);

        foreach ($need->lines as $line) {
            $plural = $line->quantity > 1 ? 's' : '';
            $common = [
                'title' => "{$line->trade}{$plural} · {$need->site->name}",
                'urgency' => $need->urgency,
                'deadline' => $deadline,
                'starts_on' => $need->starts_on,
                'ends_on' => $need->ends_on,
            ];
            if ($line->offer) {
                if (! $line->offer->manual_status) {
                    $line->offer->update($common);
                }

                continue;
            }
            $need->offers()->create([
                ...$common,
                'need_line_id' => $line->id,
                'reference' => "{$need->reference}/{$line->position}",
                'visibility' => $request->validated('visibility'),
                'published_on' => Need::referenceDate(),
                'applications_open' => true,
                'auto_renew' => $request->boolean('auto_renew'),
                'description' => "Recherche de {$line->quantity} ".mb_strtolower($line->trade)." pour le chantier {$need->site->name} à {$need->site->commune}.",
                'benefits' => $benefits ? implode(' · ', $benefits) : null,
            ]);
        }
    }

    /**
     * Pièces jointes du formulaire ; elles passent « À vérifier ».
     */
    private function saveUploads(NeedRequest $request, Need $need): void
    {
        foreach (NeedRequest::FILE_FIELDS as $field => $type) {
            if (! $request->hasFile($field)) {
                continue;
            }
            $file = $request->file($field);
            $document = $need->documents()->where('type', $type)->where('status', NeedDocumentStatus::Missing)->first() ?? $need->documents()->make(['type' => $type]);
            $document->fill([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("needs/{$need->id}/documents"),
                'added_on' => Need::referenceDate(),
                'author' => auth()->user()?->name ?? 'Système',
                'status' => NeedDocumentStatus::ToReview,
            ])->save();
            if ($type === 'Plan du chantier') {
                $need->update(['anomalies' => array_values(array_filter($need->anomalies, fn (string $a): bool => ! str_contains($a, 'Plan')))]);
            }
        }
    }

    /**
     * Valeurs du formulaire de modification : la saisie d’origine, à défaut les colonnes du besoin.
     *
     * @return array<string, mixed>
     */
    private function formValues(Need $need): array
    {
        $first = $need->lines->first();
        $offer = $need->offers->first();
        [$start, $end] = array_pad(explode(' – ', explode(' · ', $need->working_hours)[0]), 2, '');
        $duration = (int) round($need->duration() * 6 / 7);

        $derived = [
            'promoter' => $need->site->promoter_name, 'site' => $need->site->slug, 'source' => $need->source, 'district' => $need->district ?? '',
            'contact' => $need->contact, 'contact_phone' => $need->contact_phone,
            'lines' => $need->lines->map(fn (NeedLine $l): array => [
                'trade_group' => $l->trade_group, 'trade' => $l->trade, 'specialty' => $l->specialty ?? '', 'quantity' => (string) $l->quantity, 'experience' => $l->experience,
                'tools' => $l->tools ?? '', 'skills' => $l->skills, 'certifications' => $l->certifications, 'own_equipment' => $l->own_equipment, 'constraints' => $l->constraints ?? '', 'comment' => $l->comment ?? '',
            ])->all(),
            'type' => $need->type, 'urgency' => $need->urgency->value, 'starts_on' => $need->starts_on->format('Y-m-d'), 'ends_on' => $need->ends_on->format('Y-m-d'),
            'working_days' => array_values(array_filter(explode(', ', $need->working_days))), 'start_time' => $start, 'end_time' => $end,
            'justification' => $need->justification ?? '', 'coverage_deadline' => $need->coverage_deadline?->format('Y-m-d') ?? '', 'follow_up' => $need->follow_up ?? '',
            'pay_type' => $first?->pay_type ?? 'Journalier', 'daily_cost' => (string) ($first?->daily_cost ?: ''),
            'weekly_cost' => $first?->pay_type === 'Hebdomadaire' ? (string) ($first->daily_cost * 6) : '',
            'contract_amount' => in_array($first?->pay_type, ['Contractuel', 'Forfaitaire'], true) ? (string) ((int) round($first->daily_cost * $duration / 5000) * 5000) : '',
            'deadline' => $offer?->deadline->format('Y-m-d') ?? '', 'visibility' => $offer?->visibility ?? 'Ouvriers éligibles uniquement',
        ];

        return [...$derived, ...collect($need->details ?? [])->except(array_keys(NeedRequest::FILE_FIELDS))->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'sites' => NeedRequest::sitesByPromoter(),
            'siteInfo' => Site::query()->get()->mapWithKeys(fn (Site $s): array => [$s->slug => ['commune' => $s->commune, 'city' => $s->city, 'type' => $s->type, 'manager' => $s->manager, 'phone' => preg_replace('/\D/', '', substr((string) $s->manager_phone, 5))]])->all(),
            'suspendedPromoters' => Promoter::query()->where('status', PromoterStatus::Suspended)->pluck('name')->all(),
            'sources' => NeedRequest::SOURCES,
            'tradeGroups' => NeedRequest::TRADE_GROUPS,
            'trades' => NeedRequest::TRADE_DETAILS,
            'experiences' => NeedRequest::EXPERIENCES,
            'types' => NeedRequest::TYPES,
            'urgencies' => array_map(fn (NeedUrgency $u): array => ['value' => $u->value, 'label' => $u->label()], NeedUrgency::cases()),
            'delays' => NeedRequest::DELAYS,
            'frequencies' => NeedRequest::FREQUENCIES,
            'admins' => NeedRequest::ADMINS,
            'payTypes' => NeedRequest::PAY_TYPES,
            'visibilities' => NeedRequest::VISIBILITIES,
            'zones' => NeedRequest::ZONES,
            'files' => NeedRequest::FILE_FIELDS,
            'favorites' => Worker::query()->where('status', WorkerStatus::Validated)->orderBy('first_name')->get(['first_name', 'last_name', 'trade'])->map(fn (Worker $w): array => ['name' => $w->name, 'trade' => $w->trade])->all(),
            'rules' => NeedInsights::rules(),
            'today' => Need::referenceDate()->format('Y-m-d'),
        ];
    }

    /**
     * Fiche détaillée et ses onglets.
     */
    public function show(Need $need, ?string $tab = null): Response
    {
        $tab ??= '';
        $need->load([...NeedInsights::RELATIONS, 'activities', 'assignments.worker']);
        $coverage = $need->coverage();
        abort_unless(array_key_exists($tab, self::TABS) && ($tab !== 'affectations' || $coverage['selected'] > 0), 404);

        $insights = new NeedInsights;
        $base = "/besoins/{$need->slug}";
        $max = max(1, (int) $need->lines->max('quantity'));
        $offerOf = fn (NeedLine $l): ?NeedOffer => $need->offers->firstWhere('need_line_id', $l->id);

        return Inertia::render('Needs/Show', [
            'need' => [
                'slug' => $need->slug,
                'reference' => $need->reference,
                'site' => ['name' => $need->site->name, 'slug' => $need->site->slug, 'type' => $need->site->type, 'commune' => $need->site->commune, 'city' => $need->site->city, 'promoter' => $need->site->promoter_name],
                'urgency' => ['value' => $need->urgency->value, 'label' => $need->urgency->label(), 'tone' => $need->urgency->tone()],
                'type' => $need->type,
                'source' => $need->source,
                'admin' => $need->admin,
                'district' => $need->district,
                'contact' => $need->contact,
                'contactPhone' => $need->contact_phone,
                'startsOn' => $need->starts_on->format('Y-m-d'),
                'endsOn' => $need->ends_on->format('Y-m-d'),
                'duration' => $need->duration(),
                'daysToStart' => NeedInsights::daysToStart($need),
                'workingHours' => $need->working_hours,
                'justification' => $need->justification,
                'conditions' => $need->conditions,
                'createdOn' => $need->created_on->format('Y-m-d'),
                'budget' => NeedInsights::budget($need),
                'readOnly' => $need->isReadOnly(),
            ],
            'actionRef' => $insights->actionRef($need),
            'coverage' => $coverage,
            'tab' => $tab,
            'kpis' => $insights->kpis($need),
            'tabCounts' => $insights->tabCounts($need),
            'banner' => $insights->banner($need),
            'alerts' => $insights->alerts($need),
            'chronology' => array_map(fn (array $s): array => [...$s, 'tone' => NeedInsights::badge($s['status'])['tone']], $insights->chronology($need)),
            'lines' => $need->lines->map(function (NeedLine $l) use ($offerOf, $need, $max): array {
                $offer = $offerOf($l);
                $found = $offer?->stats()['selected'] ?? 0;

                return [
                    'id' => $l->id,
                    'group' => $l->trade_group,
                    'trade' => $l->trade,
                    'specialty' => $l->specialty,
                    'quantity' => $l->quantity,
                    'experience' => $l->experience,
                    'amount' => $l->amountLabel($need->duration()),
                    'payType' => $l->pay_type,
                    'requirements' => implode(', ', [...$l->skills, ...$l->certifications]) ?: '—',
                    'found' => $found,
                    'rest' => max(0, $l->quantity - $found),
                    'percent' => $l->quantity ? (int) round($found / $l->quantity * 100) : 0,
                    'height' => (int) round($l->quantity / $max * 110),
                    'canMatch' => ! $need->manual_status && $need->status() !== Need::EXPIRED && (bool) $offer?->isOpen() && $found < $l->quantity,
                ];
            })->all(),
            'offers' => $need->offers->map(fn (NeedOffer $o): array => [...NeedInsights::offerRef($o), 'urgency' => $o->urgency->label()])->all(),
            'assignments' => $need->assignments->filter(fn (NeedAssignment $a): bool => $a->status->isSelected())->map(fn (NeedAssignment $a): array => [
                'id' => $a->id,
                'reference' => $a->reference,
                'worker' => $a->worker_name,
                'workerSlug' => $a->worker?->slug,
                'trade' => $a->trade,
                'status' => ['label' => $a->status->label(), 'tone' => $a->status->tone()],
                'selectedOn' => $a->selected_on->format('Y-m-d'),
                'workerConfirmation' => NeedInsights::badge($a->status->value === 'validated' ? 'Confirmée' : 'En attente'),
                'promoterConfirmation' => NeedInsights::badge($need->promoter_validated && $a->status->value === 'validated' ? 'Confirmée' : 'En attente'),
                'pay' => number_format($a->daily_cost, 0, ',', ' ').' FCFA/jour',
                'source' => $a->source,
            ])->values()->all(),
            'documents' => $need->documents->map(fn (NeedDocument $d): array => [
                'id' => $d->id, 'type' => $d->type, 'fileName' => $d->file_name, 'addedOn' => $d->added_on?->format('Y-m-d'), 'author' => $d->author,
                'status' => ['value' => $d->status->value, 'label' => $d->status->label(), 'tone' => $d->status->tone()],
                'canDelete' => ! $need->isReadOnly() && $d->status !== NeedDocumentStatus::Missing && $d->author === auth()->user()?->name,
            ])->all(),
            'activities' => NeedActivityResource::collection($need->activities->take(300))->resolve(),
            'rules' => NeedInsights::rules(),
            'documentTypes' => NeedDocument::TYPES,
            'header' => $this->header('Fiche du besoin', '', [['Besoins', '/besoins'], [$need->reference, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Fiche texte téléchargeable.
     */
    public function sheet(Need $need): HttpResponse
    {
        $need->load(NeedInsights::RELATIONS);
        $c = $need->coverage();
        $content = implode("\n", [
            "{$need->reference} · {$need->site->name}",
            "Promoteur : {$need->site->promoter_name}",
            'Statut : '.$need->status(),
            'Urgence : '.$need->urgency->label(),
            "Couverture : {$c['selected']} / {$c['need']} ({$c['percent']} %)",
            'Début : '.$need->starts_on->format('d/m/Y'),
            ...$need->lines->map(fn (NeedLine $l): string => "- {$l->quantity} {$l->trade} ({$l->specialty})"),
        ])."\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"besoin-{$need->reference}.txt\"",
        ]);
    }
}
