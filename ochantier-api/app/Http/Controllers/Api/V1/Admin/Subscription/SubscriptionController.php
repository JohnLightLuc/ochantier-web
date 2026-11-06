<?php

namespace App\Http\Controllers\Api\V1\Admin\Subscription;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\SubscriptionDetailResource;
use App\Http\Resources\V1\Admin\SubscriptionResource;
use App\Models\Subscription;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\SubscriptionDirectory;
use App\Support\Admin\SubscriptionOperations;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abonnements des promoteurs : liste, export, fiche et décisions du Back Office.
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionDirectory $directory, private SubscriptionOperations $operations) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->directory->subscriptions($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(SubscriptionResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->counts(),
            'plans' => $this->directory->planOptions(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = SubscriptionResource::collection($this->directory->subscriptions($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'subscriptions', 'subscriptions.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Promoteur', 'Responsable', 'Formule', 'Utilisateurs', 'Montant mensuel', 'Statut', 'Abonné depuis', 'Fin de période', 'Prochaine échéance'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['promoter']['name'], $row['promoter']['owner'], $row['plan']['name'], $row['seats_used'].' / '.$row['plan']['max_users'], $row['plan']['price'], $row['status']['label'], $row['started_at'], $row['period_end'], $row['next_due_date']], ';');
            }

            fclose($out);
        }, 'abonnements.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $subscriptionId): JsonResponse
    {
        return $this->fiche($request, $subscriptionId);
    }

    public function remind(Request $request, string $subscriptionId): JsonResponse
    {
        $this->operations->remind($this->find($subscriptionId), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Promoteur relancé.');
    }

    public function schedulePlan(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:30']], ['plan_code.required' => 'Choisissez une formule.']);
        $this->operations->schedulePlan($this->find($subscriptionId), $data['plan_code'], $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Changement programmé au prochain renouvellement.');
    }

    public function cancelPlanChange(Request $request, string $subscriptionId): JsonResponse
    {
        $this->operations->cancelPlanChange($this->find($subscriptionId), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Changement de formule annulé.');
    }

    public function suspend(PromoterReasonRequest $request, string $subscriptionId): JsonResponse
    {
        $this->operations->suspend($this->find($subscriptionId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Abonnement suspendu.');
    }

    public function reactivate(Request $request, string $subscriptionId): JsonResponse
    {
        $this->operations->reactivate($this->find($subscriptionId), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Abonnement réactivé.');
    }

    public function terminate(PromoterReasonRequest $request, string $subscriptionId): JsonResponse
    {
        $this->operations->terminate($this->find($subscriptionId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Résiliation enregistrée.');
    }

    public function resume(Request $request, string $subscriptionId): JsonResponse
    {
        $this->operations->resume($this->find($subscriptionId), $request->user(), $request);

        return $this->fiche($request, $subscriptionId, 'Résiliation annulée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'plan' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function find(string $id): Subscription
    {
        return $this->directory->subscriptions([])->where('subscriptions.id', $id)->with(['promoterProfile.user', 'previousPlan'])->firstOrFail();
    }

    private function fiche(Request $request, string $id, ?string $message = null): JsonResponse
    {
        return ApiResponse::success((new SubscriptionDetailResource($this->find($id)))->resolve($request), $message);
    }
}
