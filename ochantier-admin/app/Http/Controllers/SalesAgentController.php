<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\InvitationStatus;
use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentDocumentType;
use App\Enums\SalesAgentStatus;
use App\Http\Requests\SalesAgentRequest;
use App\Http\Requests\WorkerRequest;
use App\Http\Resources\SalesAgentActivityResource;
use App\Http\Resources\SalesAgentDocumentResource;
use App\Http\Resources\SalesAgentResource;
use App\Models\SalesAgent;
use App\Models\SalesAgentDocument;
use App\Support\SalesAgentInsights;
use App\Support\SalesPerformance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesAgentController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    public const TABS = [
        '' => 'Vue d’ensemble',
        'profil-documents' => 'Profil et documents',
        'ouvriers' => 'Ouvriers enregistrés',
        'activite' => 'Activité terrain',
        'zones-disponibilites' => 'Zones et disponibilités',
        'objectifs-performances' => 'Objectifs et performances',
        'classement' => 'Classement',
        'gains-paiements' => 'Gains et paiements',
        'acces-securite' => 'Accès et sécurité',
        'historique' => 'Historique',
    ];

    public function index(Request $request, SalesPerformance $performance): Response
    {
        $filters = $request->only(['q', 'statut', 'zone', 'agence', 'disponibilite', 'performance', 'superviseur', 'periode', 'rapide', 'tri', 'sens', 'page', 'mois']);
        $all = SalesAgent::orderBy('id')->get();
        $rows = $all->map(fn (SalesAgent $a): array => $this->listRow($a, $performance));
        $filtered = $this->filterRows($rows, $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator($filtered->forPage($page, self::PER_PAGE)->values(), $filtered->count(), self::PER_PAGE, $page);
        $flags = $rows->pluck('flags');
        $option = fn (string $value, ?string $label = null): array => ['value' => $value, 'label' => $label ?? $value];
        $current = SalesPerformance::currentMonth();

        return Inertia::render('SalesAgents/Index', [
            'agents' => [
                'data' => $paginator->items(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map(fn (SalesAgentStatus $s): array => $option($s->value, $s->label()), SalesAgentStatus::cases()),
                'zone' => $all->pluck('zone')->unique()->sort()->values()->map(fn (string $z): array => $option($z))->all(),
                'agence' => $all->map->agencyLabel()->unique()->sort()->values()->map(fn (string $a): array => $option($a))->all(),
                'disponibilite' => array_map(fn (SalesAgentAvailability $a): array => $option($a->value, $a->label()), SalesAgentAvailability::cases()),
                'performance' => array_map(fn (string $p): array => $option($p), ['Excellent', 'Très bon', 'Satisfaisant', 'À surveiller', 'Insuffisant']),
                'superviseur' => $all->pluck('supervisor')->filter()->unique()->sort()->values()->map(fn (string $s): array => $option($s))->all(),
                'periode' => [$option('semaine', 'Actifs cette semaine'), $option('mois', 'Actifs ce mois'), $option('inactifs', 'Inactifs depuis plus de 30 jours')],
            ],
            'kpis' => [
                'total' => $all->count(),
                'active' => $all->where('status', SalesAgentStatus::Active)->count(),
                'recruited' => $all->sum('recruited_total'),
                'payable' => $rows->sum('monthGain') + $flags->sum('pending'),
            ],
            'todo' => [
                'objective' => $flags->where('objective', true)->count(),
                'followUp' => $flags->where('followUp', true)->count(),
                'earnings' => $flags->where('earnings', true)->count(),
                'pending' => $flags->sum('pending'),
                'previousMonth' => SalesPerformance::monthLabel(SalesPerformance::previousMonth($current)),
            ],
            'cotm' => $this->commercialOfTheMonth(in_array($filters['mois'] ?? null, SalesPerformance::months(), true) ? $filters['mois'] : $current, $performance),
            'months' => array_map(fn (string $m): array => $option($m, SalesPerformance::monthLabel($m)), SalesPerformance::months()),
            'currentMonth' => $current,
        ]);
    }

    /**
     * Ligne de la liste : identité, métriques du mois en cours, repères.
     *
     * @return array<string, mixed>
     */
    private function listRow(SalesAgent $a, SalesPerformance $performance): array
    {
        $month = SalesPerformance::currentMonth();
        $row = $performance->row($a, $month);
        $m = $row['metrics'] ?? null;
        $level = SalesPerformance::performanceLabel($row['score'] ?? null);
        $gain = $performance->gain($a, $month, $row);

        return [
            'id' => $a->id,
            'slug' => $a->slug,
            'code' => $a->code,
            'name' => $a->name,
            'firstName' => $a->first_name,
            'phone' => $a->phone,
            'email' => $a->email,
            'zone' => $a->zone,
            'agency' => $a->agencyLabel(),
            'supervisor' => $a->supervisor,
            'registered' => $m['registered'] ?? null,
            'validated' => $m['validated'] ?? null,
            'validationRate' => $m['validationRate'] ?? null,
            'objective' => $m['objective'] ?? 0,
            'score' => $row['score'] ?? null,
            'monthGain' => $gain,
            'weeklyGain' => $m ? (int) round($gain / 4) : null,
            'performance' => ['label' => $level, 'tone' => SalesPerformance::performanceTone($level)],
            'availability' => ['value' => $a->availability->value, 'label' => $a->availability->label(), 'tone' => $a->availability->tone()],
            'status' => ['value' => $a->status->value, 'label' => $a->status->label(), 'tone' => $a->status->tone()],
            'lastActivityOn' => $a->last_activity_on?->format('Y-m-d'),
            'flags' => $performance->flags($a),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        $ref = SalesPerformance::referenceDate();
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $digits = preg_replace('/\D/', '', $q);

        $rows = $rows->filter(function (array $r) use ($filters, $q, $digits, $ref): bool {
            if ($q !== '') {
                $haystack = mb_strtolower(implode(' ', [$r['name'], $r['email'], $r['code'], $r['zone'], $r['agency']]));
                $phoneMatch = strlen($digits) >= 3 && str_contains(preg_replace('/\D/', '', $r['phone']), $digits);
                if (! str_contains($haystack, $q) && ! $phoneMatch) {
                    return false;
                }
            }
            $checks = [
                'statut' => $r['status']['value'],
                'zone' => $r['zone'],
                'agence' => $r['agency'],
                'disponibilite' => $r['availability']['value'],
                'performance' => $r['performance']['label'],
                'superviseur' => $r['supervisor'],
            ];
            foreach ($checks as $key => $value) {
                if (! empty($filters[$key]) && $filters[$key] !== $value) {
                    return false;
                }
            }
            $last = $r['lastActivityOn'] ?? '0000-00-00';
            $period = match ($filters['periode'] ?? null) {
                'semaine' => $last >= $ref->copy()->subDays(7)->format('Y-m-d'),
                'mois' => $last >= $ref->copy()->subDays(30)->format('Y-m-d'),
                'inactifs' => $last < $ref->copy()->subDays(30)->format('Y-m-d'),
                default => true,
            };
            $quick = match ($filters['rapide'] ?? null) {
                'objectifs' => $r['flags']['objective'],
                'relances' => $r['flags']['followUp'],
                'gains' => $r['flags']['earnings'],
                default => true,
            };

            return $period && $quick;
        });

        $sort = ['commercial' => 'name', 'zone' => 'zone', 'taux' => 'validationRate', 'objectif' => 'objective', 'gain' => 'weeklyGain', 'performance' => 'score', 'statut' => 'status.label', 'activite' => 'lastActivityOn'][$filters['tri'] ?? ''] ?? null;

        return $sort ? $rows->sortBy(fn (array $r) => data_get($r, $sort) ?? -1, SORT_NATURAL, ($filters['sens'] ?? 'asc') === 'desc')->values() : $rows->values();
    }

    /**
     * Carte « Commercial du mois » : premier commercial éligible du mois choisi.
     *
     * @return array<string, mixed>
     */
    private function commercialOfTheMonth(string $month, SalesPerformance $performance): array
    {
        $top = $performance->topEligible($month);
        $base = ['month' => $month, 'label' => SalesPerformance::monthLabel($month), 'closed' => $performance->isClosed($month), 'top' => null];
        if (! $top) {
            return $base;
        }

        $agent = $top['agent'];
        $previousMonth = SalesPerformance::previousMonth($month);
        $previous = in_array($previousMonth, SalesPerformance::months(), true) ? $performance->row($agent, $previousMonth) : null;
        $m = $top['metrics'];

        return [...$base, 'top' => [
            'slug' => $agent->slug,
            'name' => $agent->name,
            'code' => $agent->code,
            'zone' => $agent->agencyLabel().' · '.$agent->zone,
            'score' => $top['score'],
            'evolution' => $previous ? round($top['score'] - $previous['score'], 1) : null,
            'previousLabel' => mb_strtolower(SalesPerformance::monthLabel($previousMonth)),
            'stats' => [
                ['Ouvriers enregistrés', (string) $m['registered']],
                ['Profils validés', (string) $m['validated']],
                ['Taux de validation', "{$m['validationRate']} %"],
                ['Objectif atteint', "{$m['objective']} %"],
                ['Gain du mois', number_format($performance->gain($agent, $month, $top), 0, ',', ' ').' FCFA'],
            ],
        ]];
    }

    /**
     * Export CSV (tous les commerciaux, ou la sélection passée dans `ids`).
     */
    public function export(Request $request): StreamedResponse
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));
        $agents = SalesAgent::query()->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        return response()->streamDownload(function () use ($agents): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Identifiant', 'Nom', 'Téléphone', 'E-mail', 'Zone', 'Agence', 'Type', 'Statut', 'Disponibilité'], ';');
            foreach ($agents as $a) {
                fputcsv($out, [$a->code, $a->name, $a->phone, $a->email, $a->zone, $a->agencyLabel(), $a->type, $a->status->label(), $a->availability->label()], ';');
            }
            fclose($out);
        }, 'commerciaux.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): Response
    {
        return Inertia::render('SalesAgents/Form', [
            'mode' => 'create',
            'agent' => null,
            'code' => SalesAgent::nextCode(),
            'step' => 0,
            'values' => null,
            'documents' => [],
            'options' => $this->formOptions(),
            'header' => $this->header('Ajouter un commercial', 'Parcours en 6 étapes · les champs marqués * sont obligatoires.', [['Commerciaux', '/commerciaux'], ['Nouveau commercial']]),
        ]);
    }

    public function store(SalesAgentRequest $request): RedirectResponse
    {
        $agent = DB::transaction(function () use ($request): SalesAgent {
            $data = $request->agentData();
            $startedAt = $request->validated('started_at') ?: today()->format('Y-m-d');
            $defaults = SalesAgent::defaultPay($data['type'], $data['contract'] ?? null, $startedAt);

            $agent = SalesAgent::create([
                ...$data,
                'slug' => SalesAgent::uniqueSlug($data['first_name'].' '.$data['last_name']),
                'code' => SalesAgent::nextCode(),
                'city' => ($data['city'] ?? null) ?: 'Abidjan',
                'communes' => [$data['zone'], ...$data['secondary_zones']],
                'priority_trades' => $data['specialties'],
                'started_at' => $startedAt,
                'status' => SalesAgentStatus::Invited,
                'invitation_status' => InvitationStatus::Sent,
                'availability' => SalesAgentAvailability::Available,
                'pay' => $request->payData($defaults),
                'access' => [...$request->accessData(SalesAgent::defaultAccess($data['type'], $data['zone'])), 'sent_at' => today()->format('Y-m-d'), 'expires_at' => today()->addDays(3)->format('Y-m-d')],
            ]);

            foreach (SalesAgentDocumentType::cases() as $type) {
                $agent->documents()->create(['type' => $type, 'status' => DocumentStatus::Missing]);
            }
            $this->storeFiles($request, $agent);

            $agent->logActivity(SalesAgentActivityCategory::Creation, 'a créé le commercial', null, SalesAgentStatus::Invited->label(), 'Création depuis le Back-office');
            $agent->logActivity(SalesAgentActivityCategory::Invitation, 'a envoyé l’invitation par e-mail', InvitationStatus::NotSent->label(), InvitationStatus::Sent->label(), $agent->email, 'Système', 'Système');

            return $agent;
        });

        Inertia::flash('toast', "Compte de {$agent->name} créé · invitation envoyée à {$agent->email}.");

        return to_route('sales-agents.show', [$agent, 'acces-securite']);
    }

    public function edit(SalesAgent $salesAgent, ?int $step = null): Response
    {
        abort_if($salesAgent->isArchived(), 403, 'Un commercial archivé est en lecture seule.');
        $salesAgent->load('documents');
        $a = $salesAgent;
        $pay = $a->pay;
        $access = $a->access;

        return Inertia::render('SalesAgents/Form', [
            'mode' => 'edit',
            'agent' => ['slug' => $a->slug, 'name' => $a->name, 'code' => $a->code],
            'code' => $a->code,
            'step' => max(0, min(5, ($step ?? 1) - 1)),
            'values' => [
                'first_name' => $a->first_name, 'last_name' => $a->last_name, 'gender' => $a->gender ?? '', 'birth_date' => $a->birth_date?->format('Y-m-d') ?? '',
                'nationality' => $a->nationality ?? '', 'phone' => $a->phone, 'phone_secondary' => $a->phone_secondary ?? '', 'email' => $a->email,
                'address' => $a->address ?? '', 'commune' => $a->commune ?? '', 'city' => $a->city ?? '', 'emergency_contact' => $a->emergency_contact ?? '', 'emergency_phone' => $a->emergency_phone ?? '',
                'type' => $a->type, 'job_title' => $a->job_title ?? '', 'agency' => $a->agency ?? SalesAgentRequest::NO_AGENCY, 'zone' => $a->zone, 'secondary_zones' => $a->secondary_zones,
                'supervisor' => $a->supervisor ?? '', 'started_at' => $a->started_at->format('Y-m-d'), 'contract' => $a->contract ?? '',
                'weekly_target' => (string) $a->weekly_target, 'monthly_target' => (string) $a->monthly_target, 'specialties' => $a->specialties, 'internal_note' => $a->internal_note ?? '',
                'id_type' => $a->id_type ?? '', 'id_number' => $a->id_number ?? '', 'id_issued_at' => $a->id_issued_at?->format('Y-m-d') ?? '', 'id_expires_at' => $a->id_expires_at?->format('Y-m-d') ?? '',
                'role' => $access['role'], 'level' => $access['level'], 'modules' => $access['modules'], 'data_zones' => $access['data_zones'],
                'permissions' => $access['permissions'], 'two_factor' => $access['two_factor'], 'duration' => $access['duration'],
                'pay_mode' => $pay['mode'], 'fixed' => (string) $pay['fixed'], 'per_registered' => (string) $pay['per_registered'], 'per_validated' => (string) $pay['per_validated'],
                'bonus_objective' => (string) $pay['bonus_objective'], 'bonus_cotm' => (string) $pay['bonus_cotm'], 'calc_frequency' => $pay['calc_frequency'], 'pay_frequency' => $pay['pay_frequency'],
                'pay_method' => $pay['method'], 'effective_from' => $pay['effective_from'], 'cap' => (string) $pay['cap'], 'eligibility' => $pay['eligibility'], 'pay_note' => $pay['note'] ?? '',
            ],
            'documents' => SalesAgentDocumentResource::collection($a->documents)->resolve(),
            'options' => $this->formOptions(),
            'header' => $this->header('Modifier un commercial', "{$a->name} · {$a->code}", [['Commerciaux', '/commerciaux'], [$a->name, "/commerciaux/{$a->slug}"], ['Modifier']]),
        ]);
    }

    public function update(SalesAgentRequest $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403, 'Un commercial archivé est en lecture seule.');

        DB::transaction(function () use ($request, $salesAgent): void {
            $data = $request->agentData();
            $salesAgent->update([
                ...$data,
                'started_at' => $request->validated('started_at') ?: $salesAgent->started_at,
                'communes' => array_values(array_unique([...$salesAgent->communes, $data['zone'], ...$data['secondary_zones']])),
                'pay' => $request->payData($salesAgent->pay),
                'access' => $request->accessData($salesAgent->access),
            ]);
            $this->storeFiles($request, $salesAgent);
            $salesAgent->logActivity(SalesAgentActivityCategory::Update, 'a modifié le profil', null, null, 'Informations mises à jour');
        });

        Inertia::flash('toast', 'Modifications enregistrées.');

        return to_route('sales-agents.show', $salesAgent);
    }

    /**
     * Enregistre les pièces jointes au formulaire ; elles passent « À vérifier ».
     */
    private function storeFiles(SalesAgentRequest $request, SalesAgent $agent): void
    {
        foreach (SalesAgentRequest::FILE_FIELDS as $field => $type) {
            if (! $file = $request->file($field)) {
                continue;
            }
            $agent->documents()->updateOrCreate(['type' => $type], [
                'reference' => $file->getClientOriginalName(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("sales-agents/{$agent->id}/documents"),
                'added_at' => today(),
                'status' => DocumentStatus::ToReview,
                'rejection_reason' => null,
            ]);
        }
        if ($photo = $request->file('photo')) {
            $agent->update(['photo_path' => $photo->store("sales-agents/{$agent->id}")]);
        }
    }

    /**
     * @return array<string, array<int, string>|array<string, string>>
     */
    private function formOptions(): array
    {
        return [
            'communes' => WorkerRequest::COMMUNES,
            'trades' => config('admin.trades'),
            'nationalities' => SalesAgentRequest::NATIONALITIES,
            'types' => SalesAgentRequest::TYPES,
            'agencies' => [SalesAgentRequest::NO_AGENCY, ...SalesAgentRequest::AGENCIES],
            'supervisors' => SalesAgentRequest::SUPERVISORS,
            'contracts' => SalesAgentRequest::CONTRACTS,
            'idTypes' => WorkerRequest::ID_TYPES,
            'roles' => SalesAgentRequest::ROLES,
            'levels' => SalesAgentRequest::LEVELS,
            'modules' => SalesAgentRequest::MODULES,
            'durations' => SalesAgentRequest::DURATIONS,
            'payModes' => SalesAgentRequest::PAY_MODES,
            'calcFrequencies' => SalesAgentRequest::CALC_FREQUENCIES,
            'payFrequencies' => SalesAgentRequest::PAY_FREQUENCIES,
            'payMethods' => SalesAgentRequest::PAY_METHODS,
            'permissions' => SalesAgentRequest::PERMISSIONS,
        ];
    }

    public function show(SalesPerformance $performance, SalesAgent $salesAgent, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $salesAgent->load('documents', 'periods', 'objectives', 'adjustments', 'rewards', 'appreciations', 'fieldActivities.worker', 'activities');
        $insights = new SalesAgentInsights($salesAgent, $performance);
        $month = SalesPerformance::currentMonth();
        $base = "/commerciaux/{$salesAgent->slug}";
        $top = $performance->topEligible($month);

        return Inertia::render('SalesAgents/Show', [
            'agent' => SalesAgentResource::make($salesAgent)->resolve(),
            'tab' => $tab,
            'kpis' => $insights->kpis(),
            'tabCounts' => $insights->tabCounts(),
            'banner' => $insights->banner(),
            'alerts' => $insights->alerts($tab),
            'current' => $insights->summary($month),
            'previous' => $insights->summary(SalesPerformance::previousMonth($month)),
            'leader' => $top ? ['slug' => $top['agent']->slug, 'score' => $top['score']] : null,
            'above' => ($row = $performance->row($salesAgent, $month)) && $row['rank'] > 1 ? $performance->ranking($month)[$row['rank'] - 2]['score'] : null,
            'history' => $insights->history(),
            'titles' => array_map(fn (string $m): array => ['month' => $m, 'label' => SalesPerformance::monthLabel($m)], $insights->titles()),
            'tradeBreakdown' => $insights->tradeBreakdown(),
            'documents' => SalesAgentDocumentResource::collection($this->orderedDocuments($salesAgent))->resolve(),
            'workers' => $insights->workerRows(),
            'fieldActivities' => $insights->fieldActivities(),
            'periods' => $insights->periods(),
            'objectives' => $insights->objectives(),
            'payouts' => $insights->payouts(),
            'rewards' => $salesAgent->rewards->map(fn ($r): array => ['month' => $r->month, 'label' => $r->label, 'amount' => $r->amount])->all(),
            'appreciations' => $salesAgent->appreciations->map(fn ($a): array => ['id' => $a->id, 'author' => $a->author, 'text' => $a->text, 'date' => $a->created_at->format('Y-m-d')])->all(),
            'activities' => SalesAgentActivityResource::collection($salesAgent->activities->take(300))->resolve(),
            'weights' => collect(config('admin.sales_score_weights'))->map(fn (array $w, string $k): array => ['key' => $k, 'label' => $w[0], 'weight' => $w[1]])->values()->all(),
            'referenceDate' => SalesPerformance::referenceDate()->format('Y-m-d'),
            'currentMonth' => $month,
            'options' => [
                'communes' => WorkerRequest::COMMUNES,
                'trades' => config('admin.trades'),
                'colleagues' => SalesAgent::where('status', SalesAgentStatus::Active)->whereKeyNot($salesAgent->id)->orderBy('first_name')->get()->map->name->values()->all(),
                'roles' => SalesAgentRequest::ROLES,
                'levels' => SalesAgentRequest::LEVELS,
                'durations' => SalesAgentRequest::DURATIONS,
                'permissions' => SalesAgentRequest::PERMISSIONS,
            ],
            'header' => $this->header('Fiche du commercial', '', [['Commerciaux', '/commerciaux'], [$salesAgent->name, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * @return Collection<int, SalesAgentDocument>
     */
    private function orderedDocuments(SalesAgent $agent): Collection
    {
        $order = array_flip(array_map(fn (SalesAgentDocumentType $t): string => $t->value, SalesAgentDocumentType::cases()));

        return $agent->documents->sortBy(fn ($d): int => $order[$d->type->value])->values();
    }

    /**
     * Fiche texte du commercial.
     */
    public function sheet(SalesAgent $salesAgent): HttpResponse
    {
        $a = $salesAgent;
        $content = implode("\n", [
            "{$a->name} ({$a->code})",
            "{$a->type} · {$a->agencyLabel()}",
            "Zone principale : {$a->zone}",
            "Connexion : {$a->phone}",
            "E-mail : {$a->email}",
            "Statut : {$a->status->label()}",
            "Disponibilité : {$a->availability->label()}",
        ])."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"commercial-{$a->slug}.txt\""]);
    }
}
