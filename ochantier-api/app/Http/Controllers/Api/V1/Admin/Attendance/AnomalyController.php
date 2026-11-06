<?php

namespace App\Http\Controllers\Api\V1\Admin\Attendance;

use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceAnomalyType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AttendanceAnomalyResource;
use App\Models\AttendanceAnomaly;
use App\Support\Admin\AttendanceAnomalies;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anomalies de pointage du Back Office : liste, signalement et traitement.
 */
class AnomalyController extends Controller
{
    public function __construct(private AttendanceAnomalies $anomalies) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(AttendanceAnomalyStatus::class)],
            'type' => ['nullable', Rule::enum(AttendanceAnomalyType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->anomalies->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(AttendanceAnomalyResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->anomalies->counts(),
            'types' => array_map(fn (AttendanceAnomalyType $type) => ['value' => $type->value, 'label' => $type->label()], AttendanceAnomalyType::cases()),
        ]);
    }

    public function report(Request $request, string $workDay): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (AttendanceAnomalyType $type) => $type->value, AttendanceAnomalies::REPORTABLE))],
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['type.required' => 'Choisissez le type d’anomalie.', 'comment.required' => 'Décrivez l’anomalie.', 'comment.min' => 'La description est trop courte.']);

        $day = AttendanceController::find($workDay);
        $this->anomalies->report($day, AttendanceAnomalyType::from($data['type']), trim($data['comment']), $request->user(), $request);

        return ApiResponse::success(null, 'Anomalie signalée.');
    }

    public function start(Request $request, string $anomaly): JsonResponse
    {
        $this->anomalies->start(AttendanceAnomaly::query()->findOrFail($anomaly), $request->user(), $request);

        return ApiResponse::success(null, 'Anomalie prise en charge.');
    }

    public function close(Request $request, string $anomaly): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:resolue,rejetee'],
            'resolution' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['outcome.required' => 'Choisissez l’issue.', 'resolution.required' => 'Décrivez la résolution.', 'resolution.min' => 'La résolution est trop courte.']);

        $this->anomalies->close(AttendanceAnomaly::query()->findOrFail($anomaly), AttendanceAnomalyStatus::from($data['outcome']), trim($data['resolution']), $request->user(), $request);

        return ApiResponse::success(null, 'Anomalie clôturée.');
    }
}
