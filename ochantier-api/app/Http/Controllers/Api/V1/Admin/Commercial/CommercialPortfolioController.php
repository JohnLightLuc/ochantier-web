<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Enums\CommercialProfileStatus;
use App\Enums\ProspectStatus;
use App\Http\Controllers\Api\V1\Commercial\ProspectController;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\WorkerListResource;
use App\Http\Resources\V1\Commercial\ProspectResource;
use App\Models\CommercialProfile;
use App\Models\ProspectFollowup;
use App\Models\Worker;
use App\Models\WorkerCommercialTransfer;
use App\Support\Admin\CommercialPortfolio;
use App\Support\Admin\WorkerDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ouvriers suivis par un commercial, transferts et prospects (lecture seule).
 */
class CommercialPortfolioController extends Controller
{
    public function __construct(private WorkerDirectory $workers, private CommercialPortfolio $portfolio) {}

    /**
     * Ouvriers dont il est le référent. meta : pagination, compteurs par statut et derniers transferts.
     */
    public function workers(Request $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(WorkerDirectory::STATUSES)],
            'search' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $workers = $this->workers->query([...$filters, 'commercial_id' => $model->id])->paginate(20)->withQueryString();

        $counts = Worker::query()->where('current_commercial_id', $model->id)
            ->where(fn ($query) => $query->where('verification_status', '!=', 'brouillon')->orWhere('acquisition_channel', Worker::BACK_OFFICE_CHANNEL))
            ->toBase()->selectRaw('verification_status, count(*) as total')->groupBy('verification_status')->pluck('total', 'verification_status');

        $transfers = WorkerCommercialTransfer::query()
            ->where(fn ($query) => $query->where('from_commercial_id', $model->id)->orWhere('to_commercial_id', $model->id))
            ->with(['worker', 'fromCommercial.user', 'toCommercial.user', 'transferredByAdmin'])
            ->latest('effective_at')->limit(20)->get();

        return ApiResponse::success(WorkerListResource::collection($workers->items())->resolve($request), meta: [
            'current_page' => $workers->currentPage(),
            'last_page' => $workers->lastPage(),
            'per_page' => $workers->perPage(),
            'total' => $workers->total(),
            'counts' => collect(WorkerDirectory::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) $counts->get($status, 0)])->all(),
            'transfers' => $transfers->map(fn (WorkerCommercialTransfer $transfer) => [
                'id' => $transfer->id,
                'worker' => ['id' => $transfer->worker_id, 'full_name' => trim($transfer->worker->first_name.' '.$transfer->worker->last_name)],
                'from' => $this->name($transfer->fromCommercial),
                'to' => $this->name($transfer->toCommercial),
                'direction' => $transfer->from_commercial_id === $model->id ? 'sortant' : 'entrant',
                'reason' => $transfer->reason,
                'by' => trim($transfer->transferredByAdmin->first_name.' '.$transfer->transferredByAdmin->last_name),
                'at' => $transfer->effective_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Sans worker_ids, tout le portefeuille part, prospects non transmis compris.
     */
    public function transfer(Request $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $data = $request->validate([
            'to_commercial_id' => ['required', 'uuid', Rule::exists('commercial_profiles', 'id')->where('status', CommercialProfileStatus::Actif->value)->whereNull('deleted_at'), Rule::notIn([$model->id])],
            'worker_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'worker_ids.*' => ['uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'to_commercial_id.required' => 'Choisissez le nouveau commercial.',
            'to_commercial_id.exists' => 'Choisissez un commercial actif.',
            'to_commercial_id.not_in' => 'Choisissez un autre commercial.',
            'reason.required' => 'Indiquez le motif.',
            'reason.min' => 'Le motif est trop court.',
        ]);

        $counts = $this->portfolio->transfer($model, CommercialProfile::query()->findOrFail($data['to_commercial_id']), $data['worker_ids'] ?? null, trim($data['reason']), $request->user(), $request);
        $message = $counts['workers'].' ouvrier'.($counts['workers'] > 1 ? 's transférés' : ' transféré').($counts['prospects'] ? ' et '.$counts['prospects'].' prospect'.($counts['prospects'] > 1 ? 's' : '') : '').'.';

        return ApiResponse::success($counts, $message);
    }

    /**
     * Prospects du commercial, leur avancement et leur dernière relance.
     */
    public function prospects(Request $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ProspectStatus::class)], 'page' => ['nullable', 'integer', 'min:1']]);

        $prospects = $model->prospects()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with(ProspectController::WITH)
            ->addSelect(['last_followup_at' => ProspectFollowup::query()->selectRaw('max(coalesce(done_at, planned_at))')->whereColumn('prospect_followups.prospect_id', 'prospects.id')])
            ->latest()->latest('id')->paginate(20)->withQueryString();

        $counts = $model->prospects()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::success(collect($prospects->items())->map(fn ($prospect) => [...ProspectResource::make($prospect)->resolve($request), 'last_followup_at' => $prospect->last_followup_at])->all(), meta: [
            'current_page' => $prospects->currentPage(),
            'last_page' => $prospects->lastPage(),
            'per_page' => $prospects->perPage(),
            'total' => $prospects->total(),
            'counts' => collect(ProspectStatus::cases())->mapWithKeys(fn (ProspectStatus $status) => [$status->value => (int) $counts->get($status->value, 0)])->all(),
        ]);
    }

    private function name(?CommercialProfile $commercial): ?string
    {
        return $commercial ? trim($commercial->user->first_name.' '.$commercial->user->last_name) : null;
    }
}
