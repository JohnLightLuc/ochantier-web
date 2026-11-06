<?php

namespace App\Http\Controllers\Api\V1\Admin\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payment\ScheduleIndexRequest;
use App\Http\Requests\V1\Admin\Payment\TransactionIndexRequest;
use App\Http\Resources\V1\Admin\ScheduleDetailResource;
use App\Http\Resources\V1\Admin\ScheduleListResource;
use App\Http\Resources\V1\Admin\TransactionDetailResource;
use App\Http\Resources\V1\Admin\TransactionListResource;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\PaymentDirectory;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Encaissements et échéances du Back Office : listes, exports, fiches et relance du promoteur. Le paiement reste fait
 * par le promoteur depuis son application.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentDirectory $directory, private PromoterNotifier $notifier) {}

    public function index(TransactionIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = $this->directory->transactions($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(TransactionListResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->transactionCounts(),
            ...$this->directory->transactionOptions(),
        ]);
    }

    public function export(TransactionIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = TransactionListResource::collection($this->directory->transactions($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'payments', 'payments.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Date', 'Nature', 'Libellé', 'Chantier', 'Promoteur', 'Opérateur', 'Montant', 'Frais', 'Total', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['initiated_at'], $row['purpose']['label'], $row['label'], $row['site']['label'] ?? '', $row['promoter']['name'], $row['operator'], $row['amount'], $row['fees_amount'], $row['total_amount'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'encaissements-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $transactionId): JsonResponse
    {
        return ApiResponse::success(TransactionDetailResource::make($this->transaction($transactionId))->resolve($request));
    }

    public function schedules(ScheduleIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = $this->directory->schedules($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(ScheduleListResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->scheduleCounts(),
        ]);
    }

    public function exportSchedules(ScheduleIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = ScheduleListResource::collection($this->directory->schedules($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'payments', 'payments.schedules_exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Échéance', 'Type', 'Chantier', 'Promoteur', 'Métier', 'Dû', 'Payé', 'Reste', 'État'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['due_date'], $row['type']['label'], $row['site']['label'], $row['promoter']['name'], $row['trade'], $row['amount_due'], $row['amount_paid'], $row['remaining'], $row['state']['label']], ';');
            }

            fclose($out);
        }, 'echeances-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function schedule(Request $request, string $scheduleId): JsonResponse
    {
        return ApiResponse::success(ScheduleDetailResource::make(self::findSchedule($scheduleId))->resolve($request));
    }

    /**
     * Rappelle au promoteur de régler cette échéance, une fois par jour.
     */
    public function remind(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = self::findSchedule($scheduleId);

        if (! in_array($schedule->state, ['en_retard', 'exigible', 'partiellement_payee'], true)) {
            throw ValidationException::withMessages(['payment' => 'Cette échéance n’est pas à régler.']);
        }

        if ($schedule->last_reminder_at?->isToday()) {
            throw ValidationException::withMessages(['payment' => 'Le promoteur a déjà été relancé aujourd’hui pour cette échéance.']);
        }

        if ($this->notifier->scheduleReminder($schedule, $schedule->amount_due - $schedule->amount_paid) === 0) {
            throw ValidationException::withMessages(['payment' => 'Aucun destinataire à relancer pour cette échéance.']);
        }

        $schedule->forceFill(['last_reminder_at' => now()])->save();
        AdminAudit::record($request->user(), 'payments', 'payments.reminded', $request, $schedule, null, ['site_id' => $schedule->mission->site_id, 'amount' => $schedule->amount_due - $schedule->amount_paid]);

        return ApiResponse::success(ScheduleDetailResource::make(self::findSchedule($scheduleId))->resolve($request), 'Promoteur relancé.');
    }

    public static function findSchedule(string $id): PaymentSchedule
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(PaymentDirectory::class)->schedules([])->where('payment_schedules.id', $id)->with('allocations.transaction.operator')->firstOrFail();
    }

    private function transaction(string $id): PaymentTransaction
    {
        abort_unless(str($id)->isUuid(), 404);

        return $this->directory->transactions([])->where('payment_transactions.id', $id)->with(['allocations.paymentSchedule', 'receipts.mediaFile', 'statusHistories', 'subscriptionInvoice'])->firstOrFail();
    }
}
