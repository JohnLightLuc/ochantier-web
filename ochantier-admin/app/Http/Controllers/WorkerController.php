<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Http\Requests\WorkerRequest;
use App\Http\Resources\WorkerActivityResource;
use App\Http\Resources\WorkerDocumentResource;
use App\Http\Resources\WorkerExperienceResource;
use App\Http\Resources\WorkerListResource;
use App\Http\Resources\WorkerPeriodResource;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Support\WorkerInsights;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * Colonnes triables : clé d’URL → colonne.
     */
    private const SORTABLE = ['ouvrier' => 'first_name', 'metier' => 'trade', 'experience' => 'experience_years', 'cout' => 'daily_rate', 'disponibilite' => 'availability', 'note' => 'rating', 'statut' => 'status'];

    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    public const TABS = [
        '' => 'Vue d’ensemble',
        'identite-documents' => 'Identité et documents',
        'metiers-competences' => 'Métiers et compétences',
        'disponibilites' => 'Disponibilités',
        'affectations' => 'Affectations et missions',
        'pointages' => 'Pointages',
        'paiements' => 'Paiements et gains',
        'avis' => 'Avis et notes',
        'remplacements' => 'Indisponibilités et remplacements',
        'historique' => 'Historique',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'metier', 'niveau', 'disponibilite', 'statut', 'localisation', 'cout', 'note', 'rapide', 'tri', 'sens', 'page']);
        $paginator = $this->filteredQuery($filters)->paginate(self::PER_PAGE)->withQueryString();
        $all = Worker::with('documents')->get();
        $flags = $all->map(fn (Worker $w): array => $w->flags());
        $option = fn (string $value, ?string $label = null): array => ['value' => $value, 'label' => $label ?? $value];

        return Inertia::render('Workers/Index', [
            'workers' => [
                'data' => WorkerListResource::collection($paginator->items())->resolve(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'metier' => $all->pluck('trade')->unique()->sort()->values()->map(fn (string $t): array => $option($t))->all(),
                'niveau' => [$option('debutant', 'Débutant (moins de 3 ans)'), $option('confirme', 'Confirmé (3 à 6 ans)'), $option('expert', 'Expert (7 ans et +)')],
                'disponibilite' => array_map(fn (WorkerAvailability $a): array => $option($a->value, $a->label()), WorkerAvailability::cases()),
                'statut' => array_map(fn (WorkerStatus $s): array => $option($s->value, $s->label()), WorkerStatus::cases()),
                'localisation' => $all->pluck('commune')->filter()->unique()->sort()->values()->map(fn (string $c): array => $option($c))->all(),
                'cout' => [$option('moins-9000', 'Moins de 9 000 FCFA'), $option('9000-11000', '9 000 à 11 000 FCFA'), $option('plus-11000', 'Plus de 11 000 FCFA')],
                'note' => [$option('4.5', '4,5 et plus'), $option('4', '4 à 4,4'), $option('moins-4', 'Moins de 4')],
            ],
            'kpis' => [
                'total' => $all->count(),
                'available' => $all->where('availability', WorkerAvailability::Available)->count(),
                'onMission' => $all->where('availability', WorkerAvailability::OnMission)->count(),
                'toReview' => $all->filter(fn (Worker $w): bool => $w->status->isUnderReview())->count(),
            ],
            'todo' => [
                'review' => $flags->where('review', true)->count(),
                'replacement' => $flags->where('replacement', true)->count(),
                'documents' => $flags->where('documents', true)->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Worker>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = Worker::query()->search($filters['q'] ?? null);

        if (! empty($filters['metier'])) {
            $query->where('trade', $filters['metier']);
        }
        match ($filters['niveau'] ?? null) {
            'debutant' => $query->where('experience_years', '<', 3),
            'confirme' => $query->whereBetween('experience_years', [3, 6]),
            'expert' => $query->where('experience_years', '>=', 7),
            default => null,
        };
        if ($availability = WorkerAvailability::tryFrom($filters['disponibilite'] ?? '')) {
            $query->where('availability', $availability);
        }
        if ($status = WorkerStatus::tryFrom($filters['statut'] ?? '')) {
            $query->where('status', $status);
        }
        if (! empty($filters['localisation'])) {
            $query->where('commune', $filters['localisation']);
        }
        match ($filters['cout'] ?? null) {
            'moins-9000' => $query->where('daily_rate', '<', 9000),
            '9000-11000' => $query->whereBetween('daily_rate', [9000, 11000]),
            'plus-11000' => $query->where('daily_rate', '>', 11000),
            default => null,
        };
        match ($filters['note'] ?? null) {
            '4.5' => $query->where('rating', '>=', 4.5),
            '4' => $query->where('rating', '>=', 4)->where('rating', '<', 4.5),
            'moins-4' => $query->where('rating', '<', 4),
            default => null,
        };
        match ($filters['rapide'] ?? null) {
            'verification' => $query->whereIn('status', [WorkerStatus::ToReview, WorkerStatus::InReview]),
            'remplacements' => $query->whereIn('id', Worker::with('documents')->get()->filter(fn (Worker $w): bool => $w->flags()['replacement'])->pluck('id')),
            'pieces' => $query->whereHas('documents', fn (Builder $q) => $q->whereIn('status', [DocumentStatus::Expired, DocumentStatus::Missing])),
            default => null,
        };

        $column = self::SORTABLE[$filters['tri'] ?? ''] ?? null;

        return $column
            ? $query->orderBy($column, ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('id')
            : $query->orderBy('id');
    }

    /**
     * Export CSV (tous les ouvriers, ou la sélection passée dans `ids`).
     */
    public function export(Request $request): StreamedResponse
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));
        $workers = Worker::query()->when($ids, fn (Builder $q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        return response()->streamDownload(function () use ($workers): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Identifiant', 'Prénom', 'Nom', 'Métier', 'Expérience', 'Coût journalier (FCFA)', 'Disponibilité', 'Note', 'Statut', 'Téléphone', 'Localisation'], ';');
            foreach ($workers as $w) {
                fputcsv($out, [$w->code, $w->first_name, $w->last_name, $w->trade, $w->experience_years, $w->daily_rate, $w->availability->label(), $w->rating ?: '', $w->status->label(), $w->phone, $w->commune], ';');
            }
            fclose($out);
        }, 'ouvriers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): Response
    {
        return Inertia::render('Workers/Form', [
            'mode' => 'create',
            'worker' => null,
            'step' => 0,
            'values' => null,
            'documents' => [],
            'options' => $this->formOptions(),
            'header' => $this->header('Ajouter un ouvrier', 'Parcours en 6 étapes · les champs marqués * sont obligatoires.', [['Ouvriers', '/ouvriers'], ['Nouvel ouvrier']]),
        ]);
    }

    public function store(WorkerRequest $request): RedirectResponse
    {
        $worker = DB::transaction(function () use ($request): Worker {
            $data = $request->workerData();
            $choice = $request->validated('availability_choice');

            $worker = Worker::create([
                ...$data,
                'slug' => Worker::uniqueSlug($data['first_name'].' '.$data['last_name']),
                'code' => Worker::nextCode(),
                'city' => ($data['city'] ?? null) ?: 'Abidjan',
                'country' => ($data['country'] ?? null) ?: 'Côte d’Ivoire',
                'certifications' => [],
                'status' => WorkerStatus::ToReview,
                'availability' => $choice === 'Indisponible' ? WorkerAvailability::Unavailable : WorkerAvailability::Available,
                'registered_at' => $request->validated('registered_at') ?: today(),
                'demo_relations' => ['assignments' => [], 'timesheets' => [], 'payouts' => [], 'reviews' => [], 'replacements' => [], 'stats' => ['missions' => 0, 'days' => 0, 'presence' => 0, 'acceptance' => 0, 'cancellation' => 0]],
            ]);

            foreach (WorkerDocumentType::cases() as $type) {
                $worker->documents()->create(['type' => $type, 'status' => DocumentStatus::Missing]);
            }
            $this->saveUploads($request, $worker);
            $this->syncExperiences($request, $worker);
            $worker->logActivity(WorkerActivityCategory::Creation, 'a créé le profil', null, WorkerStatus::ToReview->label(), 'Soumis pour vérification');

            return $worker;
        });

        Inertia::flash('toast', "Profil de {$worker->name} soumis pour vérification.");

        return to_route('workers.show', $worker);
    }

    public function edit(Request $request, Worker $worker, ?int $step = null): Response
    {
        abort_if($worker->isArchived(), 403, 'Profil archivé : réactivez-le pour le modifier.');
        $worker->load('documents', 'experiences');

        return Inertia::render('Workers/Form', [
            'mode' => 'edit',
            'worker' => ['slug' => $worker->slug, 'name' => $worker->name, 'code' => $worker->code],
            'step' => max(0, min(5, ($step ?? 1) - 1)),
            'values' => [
                'first_name' => $worker->first_name, 'last_name' => $worker->last_name, 'gender' => $worker->gender ?? '', 'birth_date' => $worker->birth_date?->format('Y-m-d') ?? '',
                'nationality' => $worker->nationality ?? '', 'phone' => $worker->phone, 'phone_secondary' => $worker->phone_secondary ?? '', 'email' => $worker->email ?? '',
                'address' => $worker->address ?? '', 'commune' => $worker->commune ?? '', 'city' => $worker->city ?? '', 'country' => $worker->country ?? '',
                'emergency_contact' => $worker->emergency_contact ?? '', 'emergency_phone' => $worker->emergency_phone ?? '',
                'trade' => $worker->trade, 'qualification' => $worker->qualification ?? '', 'secondary_trades' => $worker->secondary_trades,
                'specialties' => implode(', ', $worker->specialties), 'skills' => implode(', ', $worker->skills),
                'experience_years' => (string) $worker->experience_years, 'daily_rate' => (string) $worker->daily_rate, 'experience_description' => $worker->experience_description ?? '',
                'availability_choice' => $worker->availability === WorkerAvailability::Unavailable ? 'Indisponible' : 'Disponible', 'mobility' => $worker->mobility ?? '', 'zones' => $worker->zones,
                'works_solo' => $worker->works_solo, 'works_in_team' => $worker->works_in_team, 'accepts_contracts' => $worker->accepts_contracts,
                'experiences' => $worker->experiences->map(fn ($e): array => [
                    'company' => $e->company, 'mission' => $e->mission, 'trade' => $e->trade, 'site' => $e->site ?? '', 'location' => $e->location ?? '',
                    'reference' => $e->reference ?? '', 'started_at' => $e->started_at->format('Y-m-d'), 'ended_at' => $e->ended_at?->format('Y-m-d') ?? '', 'description' => $e->description ?? '',
                ])->all(),
                'id_type' => $worker->id_type ?? '', 'id_number' => $worker->id_number ?? '', 'id_issued_at' => $worker->id_issued_at?->format('Y-m-d') ?? '', 'id_expires_at' => $worker->id_expires_at?->format('Y-m-d') ?? '',
                'recruiter' => $worker->recruiter ?? '', 'sales_zone' => $worker->sales_zone ?? '', 'registered_at' => $worker->registered_at->format('Y-m-d'),
                'acquisition_channel' => $worker->acquisition_channel ?? '', 'sales_note' => $worker->sales_note ?? '',
            ],
            'documents' => WorkerDocumentResource::collection($this->orderedDocuments($worker))->resolve(),
            'options' => $this->formOptions(),
            'header' => $this->header('Modifier un ouvrier', "{$worker->name} · {$worker->code}", [['Ouvriers', '/ouvriers'], [$worker->name, "/ouvriers/{$worker->slug}"], ['Modifier']]),
        ]);
    }

    public function update(WorkerRequest $request, Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        DB::transaction(function () use ($request, $worker): void {
            $worker->update($request->workerData());
            $this->saveUploads($request, $worker);
            $this->syncExperiences($request, $worker);
            $worker->logActivity(WorkerActivityCategory::Update, 'a modifié le profil', null, null, 'Informations mises à jour');
        });

        Inertia::flash('toast', 'Modifications enregistrées.');

        return to_route('workers.show', $worker);
    }

    /**
     * Enregistre les pièces déposées dans le formulaire ; elles passent « À vérifier ».
     */
    private function saveUploads(WorkerRequest $request, Worker $worker): void
    {
        if ($request->hasFile('photo')) {
            if ($worker->photo_path) {
                Storage::delete($worker->photo_path);
            }
            $worker->update(['photo_path' => $request->file('photo')->store("workers/{$worker->id}")]);
        }

        foreach (WorkerRequest::FILE_FIELDS as $field => $type) {
            if (! $request->hasFile($field)) {
                continue;
            }
            $file = $request->file($field);
            $document = $worker->documents()->firstOrNew(['type' => $type]);
            if ($document->file_path) {
                Storage::delete($document->file_path);
            }
            $document->fill([
                'reference' => in_array($type, [WorkerDocumentType::IdFront, WorkerDocumentType::IdBack], true) && $worker->id_number ? $worker->id_number : $file->getClientOriginalName(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("workers/{$worker->id}/documents"),
                'added_at' => today(),
                'status' => DocumentStatus::ToReview,
                'rejection_reason' => null,
            ])->save();
        }
    }

    private function syncExperiences(WorkerRequest $request, Worker $worker): void
    {
        $worker->experiences()->delete();

        foreach ($request->validated('experiences', []) as $experience) {
            $worker->experiences()->create([...$experience, 'trade' => $experience['trade'] ?? $worker->trade]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function formOptions(): array
    {
        return [
            'trades' => config('admin.trades'),
            'communes' => WorkerRequest::COMMUNES,
            'nationalities' => WorkerRequest::NATIONALITIES,
            'qualifications' => WorkerRequest::QUALIFICATIONS,
            'mobilities' => WorkerRequest::MOBILITIES,
            'idTypes' => WorkerRequest::ID_TYPES,
            'recruiters' => WorkerRequest::RECRUITERS,
            'salesZones' => WorkerRequest::SALES_ZONES,
            'channels' => WorkerRequest::CHANNELS,
            'availabilityChoices' => WorkerRequest::AVAILABILITY_CHOICES,
        ];
    }

    /**
     * @return iterable<int, WorkerDocument>
     */
    private function orderedDocuments(Worker $worker): iterable
    {
        return $worker->documents->sortBy(fn (WorkerDocument $d): int => array_search($d->type, WorkerDocumentType::cases(), true))->values();
    }

    /**
     * Fiche détaillée et ses onglets.
     */
    public function show(Worker $worker, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $worker->load('documents', 'experiences', 'periods', 'activities');
        $insights = new WorkerInsights($worker);
        $base = "/ouvriers/{$worker->slug}";

        return Inertia::render('Workers/Show', [
            'worker' => WorkerResource::make($worker)->resolve(),
            'tab' => $tab,
            'kpis' => $insights->kpis(),
            'tabCounts' => $insights->tabCounts(),
            'banner' => $insights->banner(),
            'alerts' => $insights->alerts($tab),
            'documents' => WorkerDocumentResource::collection($this->orderedDocuments($worker))->resolve(),
            'experiences' => WorkerExperienceResource::collection($worker->experiences)->resolve(),
            'periods' => WorkerPeriodResource::collection($worker->periods)->resolve(),
            'activities' => WorkerActivityResource::collection($worker->activities->take(300))->resolve(),
            'demo' => $insights->demo(),
            'candidates' => $tab === 'remplacements' ? $insights->replacementCandidates() : [],
            'commissionRate' => (int) config('admin.worker_commission_rate'),
            'options' => [
                'trades' => config('admin.trades'),
                'communes' => WorkerRequest::COMMUNES,
                'sites' => array_map(fn (array $s): string => $s[0], config('admin.demo_sites')),
            ],
            'header' => $this->header('Fiche de l’ouvrier', '', [['Ouvriers', '/ouvriers'], [$worker->name, $tab ? $base : null], ...($tab ? [[self::TABS[$tab]]] : [])]),
        ]);
    }

    /**
     * Fiche texte téléchargeable.
     */
    public function sheet(Worker $worker): HttpResponse
    {
        $content = implode("\n", [
            "{$worker->name} ({$worker->code})",
            "{$worker->trade} · {$worker->experience_years} ans",
            'Statut : '.$worker->status->label(),
            'Disponibilité : '.$worker->availability->label(),
            "Téléphone : {$worker->phone}",
            'Coût : '.number_format($worker->daily_rate, 0, ',', ' ').' FCFA/jour',
        ])."\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"fiche-{$worker->slug}.txt\"",
        ]);
    }
}
