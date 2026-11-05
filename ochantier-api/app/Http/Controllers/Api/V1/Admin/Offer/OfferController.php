<?php

namespace App\Http\Controllers\Api\V1\Admin\Offer;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Offer\OfferIndexRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\OfferDetailResource;
use App\Http\Resources\V1\Admin\OfferListResource;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\OfferDirectory;
use App\Support\Admin\OfferOperations;
use App\Support\ApiResponse;
use App\Support\Documents\DocumentStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Besoins du Back Office : liste, export, fiche et ses onglets, décisions.
 */
class OfferController extends Controller
{
    private const ACTIONS = [
        'offer.suspended' => 'a suspendu le besoin',
        'offer.resumed' => 'a repris le besoin',
        'offer.extended' => 'a prolongé la date limite',
        'offer.cancelled' => 'a annulé le besoin',
        'offer.closed' => 'a clôturé le besoin',
    ];

    public function __construct(private OfferDirectory $directory, private OfferOperations $operations) {}

    public function index(OfferIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $offers = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(OfferListResource::collection($offers->items())->resolve($request), meta: [
            'current_page' => $offers->currentPage(),
            'last_page' => $offers->lastPage(),
            'per_page' => $offers->perPage(),
            'total' => $offers->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    public function export(OfferIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = OfferListResource::collection($this->directory->query($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'offers', 'offers.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Besoin', 'Chantier', 'Promoteur', 'Commune', 'Métier principal', 'Postes', 'Pourvus', 'Démarrage', 'Date limite', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['title'], $row['site']['label'], $row['promoter']['name'], $row['site']['commune'], $row['main_trade'], $row['coverage']['required'], $row['coverage']['assigned'], $row['start_date'], $row['application_deadline'] ? substr($row['application_deadline'], 0, 10) : null, $row['status']['label']], ';');
            }

            fclose($out);
        }, 'besoins-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $offer): JsonResponse
    {
        return $this->fiche($request, $offer);
    }

    /**
     * Propositions présentées au promoteur et ouvriers affectés.
     */
    public function workers(string $offer): JsonResponse
    {
        $model = self::find($offer);
        $model->loadMissing(['needs.assignments.worker', 'needs.trade']);

        return ApiResponse::success([
            'proposals' => $model->proposals()->with('refusalReason')->latest('sequence')->get()->map(fn (Proposal $proposal) => [
                'id' => $proposal->id,
                'reference' => $proposal->reference,
                'sequence' => $proposal->sequence,
                'status' => ['value' => $proposal->status->value, 'label' => $proposal->status->label()],
                'requested' => $proposal->requested_count,
                'proposed' => $proposal->proposed_count,
                'is_partial' => $proposal->is_partial,
                'total_amount' => $proposal->total_amount,
                'deposit_amount' => $proposal->deposit_amount,
                'published_at' => $proposal->published_at?->toIso8601String(),
                'decided_at' => $proposal->decided_at?->toIso8601String(),
                'refusal' => $proposal->refusalReason?->label,
            ])->all(),
            'assigned' => $model->needs->flatMap(fn ($need) => $need->assignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'worker' => ['id' => $assignment->worker_id, 'full_name' => trim($assignment->worker->first_name.' '.$assignment->worker->last_name)],
                'trade' => $need->trade?->label,
                'status' => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
                'start_date' => $assignment->planned_start_date?->toDateString(),
                'end_date' => $assignment->planned_end_date?->toDateString(),
            ]))->values()->all(),
        ]);
    }

    public function documents(Request $request, string $offer): JsonResponse
    {
        return ApiResponse::success(DocumentResource::collection(app(DocumentStore::class)->active(self::find($offer)))->resolve($request));
    }

    public function history(Request $request, string $offer): JsonResponse
    {
        $model = self::find($offer);
        $page = max(1, (int) $request->query('page', '1'));
        $histories = $model->statusHistories()->where('actor_type', '!=', (new Admin)->getMorphClass())->get();
        $users = User::query()->whereIn('id', $histories->pluck('actor_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $events = $histories->toBase()->map(fn (OfferStatusHistory $history) => [
            'id' => 'statut:'.$history->id,
            'origin' => $history->actor_type === (new User)->getMorphClass() ? 'promoteur' : 'systeme',
            'author' => ($user = $users->get($history->actor_id)) ? trim($user->first_name.' '.$user->last_name) : 'Système',
            'what' => 'a changé le statut',
            'detail' => $history->reason,
            'from' => $history->from_status?->label(),
            'to' => $history->to_status?->label(),
            'at' => $history->created_at,
        ])->concat(AuditLog::query()->where('subject_type', $model->getMorphClass())->where('subject_id', $model->getKey())->whereIn('action', array_keys(self::ACTIONS))->with('actor')->get()->toBase()->map(fn (AuditLog $log) => [
            'id' => 'audit:'.$log->id,
            'origin' => 'admin',
            'author' => $log->actor instanceof Admin ? $log->actor->fullName() : 'Administrateur',
            'what' => self::ACTIONS[$log->action],
            'detail' => $log->after_values['reason'] ?? null,
            'from' => null,
            'to' => null,
            'at' => $log->created_at,
        ]))->sortByDesc('at')->values();

        $history = new LengthAwarePaginator($events->forPage($page, 20)->map(fn (array $event) => [...$event, 'at' => $event['at']?->toIso8601String()])->values()->all(), $events->count(), 20, $page);

        return ApiResponse::success($history->items(), meta: ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'per_page' => $history->perPage(), 'total' => $history->total()]);
    }

    public function suspend(PromoterReasonRequest $request, string $offer): JsonResponse
    {
        $this->operations->suspend(self::find($offer), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $offer, 'Besoin suspendu : la recherche est arrêtée.');
    }

    public function resume(Request $request, string $offer): JsonResponse
    {
        $this->operations->resume(self::find($offer), $request->user(), $request);

        return $this->fiche($request, $offer, 'Besoin repris.');
    }

    public function extend(Request $request, string $offer): JsonResponse
    {
        $data = $request->validate(['deadline' => ['required', 'date', 'after:now']], ['deadline.required' => 'Indiquez la nouvelle date limite.', 'deadline.after' => 'La date limite doit être dans le futur.']);
        $this->operations->extend(self::find($offer), Carbon::parse($data['deadline'])->endOfDay(), $request->user(), $request);

        return $this->fiche($request, $offer, 'Date limite prolongée.');
    }

    public function cancel(PromoterReasonRequest $request, string $offer): JsonResponse
    {
        $this->operations->cancel(self::find($offer), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $offer, 'Besoin annulé.');
    }

    public function close(PromoterReasonRequest $request, string $offer): JsonResponse
    {
        $this->operations->close(self::find($offer), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $offer, 'Besoin clôturé.');
    }

    private function fiche(Request $request, string $offer, ?string $message = null): JsonResponse
    {
        return ApiResponse::success(OfferDetailResource::make(self::find($offer))->resolve($request), $message);
    }

    public static function find(string $id): Offer
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(OfferDirectory::class)->query([])->where('offers.id', $id)->with(['needs' => fn ($query) => $query->orderBy('reference')->with(['trade', 'assignments'])])->firstOrFail();
    }
}
