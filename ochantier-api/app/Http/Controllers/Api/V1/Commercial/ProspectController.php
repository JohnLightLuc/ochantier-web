<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Enums\ProspectStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Requests\V1\Commercial\AbandonProspectRequest;
use App\Http\Requests\V1\Commercial\ProspectRequest;
use App\Http\Requests\V1\DialCodeRule;
use App\Http\Requests\V1\PhoneRule;
use App\Http\Resources\V1\Commercial\ProspectDetailResource;
use App\Http\Resources\V1\Commercial\ProspectResource;
use App\Models\Prospect;
use App\Models\ReasonCode;
use App\Support\Commercial\ProspectDossier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Prospects du commercial connecté (« Suivez vos contacts terrain ») et leur dossier ouvrier.
 */
class ProspectController extends Controller
{
    /**
     * Relations utilisées par la liste et la fiche (avancement du dossier compris).
     */
    public const WITH = [
        'commune', 'neighborhood', 'presumedTrade', 'convertedWorker',
        'draftWorker.documents.documentType', 'draftWorker.documents.files',
    ];

    public function __construct(private ProspectDossier $dossier) {}

    /**
     * Prospects en cours (hors convertis et archivés, sauf filtre « status »), du plus récemment modifié au plus ancien.
     * Filtres : search (nom), commune_id, status, progress (lt50, 50_99, 100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'commune_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(ProspectStatus::class)],
            'progress' => ['nullable', 'in:lt50,50_99,100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $prospects = EnsureActiveCommercial::profile($request)->prospects()
            ->with(self::WITH)
            ->when($filters['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status),
                fn ($query) => $query->whereNotIn('status', [ProspectStatus::Converti, ProspectStatus::Abandonne]))
            ->when($filters['commune_id'] ?? null, fn ($query, $commune) => $query->where('commune_id', $commune))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereRaw(
                "(first_name || ' ' || coalesce(last_name, '')) ILIKE ?",
                ['%'.addcslashes($search, '%_\\').'%'],
            ))
            ->latest('updated_at')
            ->get();

        if ($progress = $filters['progress'] ?? null) {
            $prospects = $prospects->filter(function (Prospect $prospect) use ($progress): bool {
                $percent = $this->dossier->completion($prospect)['percent'];

                return match ($progress) {
                    'lt50' => $percent < 50,
                    '50_99' => $percent >= 50 && $percent < 100,
                    '100' => $percent === 100,
                };
            })->values();
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return ProspectResource::collection(new LengthAwarePaginator(
            $prospects->forPage($page, $perPage)->values(),
            $prospects->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        ));
    }

    /**
     * Enregistre un prospect (« Enregistrer le prospect »). Avec un client_uuid déjà reçu, renvoie le prospect existant :
     * une même saisie hors ligne n’est jamais créée deux fois.
     */
    public function store(ProspectRequest $request): JsonResponse
    {
        $commercial = EnsureActiveCommercial::profile($request);

        if ($uuid = $request->input('client_uuid')) {
            $existing = $commercial->prospects()->withTrashed()->where('client_uuid', $uuid)->first();

            if ($existing) {
                return ProspectDetailResource::make($existing->load(self::WITH))->response()->setStatusCode(200);
            }
        }

        $prospect = $this->dossier->create($commercial, $request->user(), $request->validated(), $request->ip(), $request->header('X-Device-Id'));

        return ProspectDetailResource::make($prospect->load(self::WITH))
            ->additional(['message' => 'Prospect enregistré'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Prospect $prospect): ProspectDetailResource
    {
        return ProspectDetailResource::make($prospect->load(self::WITH));
    }

    /**
     * Modifie le contact ou complète le dossier ; seuls les champs envoyés sont enregistrés. Avec lock_version, une
     * modification faite entre-temps sur un autre appareil est refusée (409).
     */
    public function update(ProspectRequest $request, Prospect $prospect): ProspectDetailResource
    {
        $prospect = $this->dossier->update($prospect, $request->validated());

        return ProspectDetailResource::make($prospect->load(self::WITH))->additional(['message' => 'Dossier mis à jour']);
    }

    /**
     * Archive le prospect avec un motif.
     */
    public function abandon(AbandonProspectRequest $request, Prospect $prospect): ProspectDetailResource
    {
        $reason = ReasonCode::query()
            ->where('category', AbandonProspectRequest::REASON_CATEGORY)
            ->where('code', $request->input('reason_code'))
            ->firstOrFail();

        $prospect = $this->dossier->abandon($prospect, $reason, $request->input('comment'));

        return ProspectDetailResource::make($prospect->load(self::WITH))->additional(['message' => 'Prospect archivé']);
    }

    /**
     * Transmet le dossier complet au Back office (« Soumettre au Back office »).
     */
    public function submit(Request $request, Prospect $prospect): JsonResponse
    {
        $worker = $this->dossier->submit($prospect, $request->user());

        return response()->json([
            'message' => 'Dossier transmis au Back office',
            'worker' => [
                'id' => $worker->id,
                'reference' => $worker->reference,
                'status' => ['value' => $worker->verification_status->value, 'label' => $worker->verification_status->label()],
                'submitted_at' => $worker->submitted_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Contrôle des doublons pendant la saisie du numéro (« Aucun doublon trouvé »).
     */
    public function checkPhone(Request $request): JsonResponse
    {
        $request->validate([
            'dial_code' => ['required', 'string', 'max:6', new DialCodeRule],
            'phone_number' => ['required', 'string', 'max:20', new PhoneRule],
            'prospect_id' => ['nullable', 'uuid'],
        ], ['dial_code.required' => 'Sélectionnez l’indicatif du pays.', 'phone_number.required' => 'Saisissez un numéro.']);

        $commercial = EnsureActiveCommercial::profile($request);
        $ignore = $request->input('prospect_id') ? $commercial->prospects()->find($request->input('prospect_id')) : null;
        $phone = PhoneNumber::from($request->input('dial_code'), $request->input('phone_number'));
        $conflict = $this->dossier->phoneConflict($commercial, $phone, $ignore);

        return response()->json([
            ...$phone->toArray(),
            'available' => $conflict === null,
            'message' => $conflict ?? 'Aucun doublon trouvé',
        ]);
    }
}
