<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Enums\CommercialAdjustmentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\AuditLog;
use App\Models\CommercialAdjustment;
use App\Models\CommercialAppreciation;
use App\Models\CommercialProfile;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\CommercialCompensation;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gains, primes et retenues d’un commercial, appréciations internes et historique de la fiche.
 */
class CommercialCompensationController extends Controller
{
    /**
     * Action du journal d’audit => libellé de l’historique.
     */
    private const ACTIONS = [
        'commercial.created' => 'a créé le commercial',
        'commercial.updated' => 'a modifié la fiche',
        'commercial.suspended' => 'a suspendu le compte',
        'commercial.reactivated' => 'a réactivé le compte',
        'commercial.terminated' => 'a mis fin au contrat',
        'commercial.access_link_sent' => 'a envoyé un lien d’accès',
        'commercial.access_link_revoked' => 'a révoqué le lien d’accès',
        'commercial.sessions_revoked' => 'a fermé des sessions',
        'commercial.portfolio_transferred' => 'a transféré des ouvriers',
        'commercial.zones_updated' => 'a modifié les zones',
        'commercial.zone_request_refused' => 'a refusé une demande de zone',
        'commercial.objective_set' => 'a fixé un objectif',
        'commercial.adjustment_proposed' => 'a proposé un ajustement',
        'commercial.adjustment_approved' => 'a approuvé un ajustement',
        'commercial.adjustment_refused' => 'a refusé un ajustement',
        'commercial.appreciation_added' => 'a ajouté une appréciation',
    ];

    public function __construct(private CommercialCompensation $compensation) {}

    public function earnings(Request $request, string $commercial): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;

        return ApiResponse::success($this->compensation->overview(CommercialController::find($commercial), $month ? CarbonImmutable::createFromFormat('!Y-m', $month) : CarbonImmutable::today()->startOfMonth(), $request->user()));
    }

    public function propose(Request $request, string $commercial): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CommercialAdjustmentKind::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'granted_on' => ['nullable', 'date'],
        ], ['amount.required' => 'Indiquez le montant.', 'amount.min' => 'Le montant doit être positif.', 'reason.required' => 'Indiquez le motif.', 'reason.min' => 'Le motif est trop court.']);

        $this->compensation->propose(CommercialController::find($commercial), $data, $request->user(), $request);

        return ApiResponse::success(message: 'Proposition enregistrée : une autre personne doit l’approuver.', status: 201);
    }

    public function approve(Request $request, string $commercial, string $adjustment): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->compensation->approve($model, $this->adjustment($model, $adjustment), $request->user(), $request);

        return ApiResponse::success(message: 'Ajustement approuvé.');
    }

    public function refuse(PromoterReasonRequest $request, string $commercial, string $adjustment): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->compensation->refuse($model, $this->adjustment($model, $adjustment), $request->reason(), $request->user(), $request);

        return ApiResponse::success(message: 'Ajustement refusé.');
    }

    /**
     * Appréciations internes, jamais montrées au commercial.
     */
    public function appreciations(string $commercial): JsonResponse
    {
        return ApiResponse::success(CommercialController::find($commercial)->commercialAppreciations()->with('authorAdmin')->latest()->latest('id')->limit(30)->get()->map(fn (CommercialAppreciation $appreciation) => [
            'id' => $appreciation->id,
            'body' => $appreciation->body,
            'author' => trim($appreciation->authorAdmin->first_name.' '.$appreciation->authorAdmin->last_name),
            'created_at' => $appreciation->created_at?->toIso8601String(),
        ])->all());
    }

    public function storeAppreciation(Request $request, string $commercial): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:3', 'max:2000']], ['body.required' => 'Saisissez l’appréciation.']);
        $model = CommercialController::find($commercial);

        $model->commercialAppreciations()->create(['author_admin_id' => $request->user()->getKey(), 'body' => trim($data['body'])]);
        AdminAudit::record($request->user(), 'commercials', 'commercial.appreciation_added', $request, $model);

        return ApiResponse::success(message: 'Appréciation enregistrée.', status: 201);
    }

    /**
     * Historique des actions du Back Office sur la fiche.
     */
    public function history(string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $logs = AuditLog::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->whereIn('action', array_keys(self::ACTIONS))
            ->with('actor')
            ->latest()->latest('id')->paginate(20);

        return ApiResponse::success(collect($logs->items())->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'what' => self::ACTIONS[$log->action],
            'author' => $log->actor ? trim($log->actor->first_name.' '.$log->actor->last_name) : 'Système',
            'detail' => $log->after_values['reason'] ?? null,
            'at' => $log->created_at?->toIso8601String(),
        ])->all(), meta: ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total()]);
    }

    private function adjustment(CommercialProfile $commercial, string $id): CommercialAdjustment
    {
        return $commercial->commercialAdjustments()->whereKey($id)->firstOrFail();
    }
}
