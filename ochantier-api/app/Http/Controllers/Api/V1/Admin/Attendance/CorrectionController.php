<?php

namespace App\Http\Controllers\Api\V1\Admin\Attendance;

use App\Enums\AttendanceCorrectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use App\Support\Admin\AttendanceCorrections;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Corrections de pointage du Back Office : liste, demande et décision d’une autre personne.
 */
class CorrectionController extends Controller
{
    public function __construct(private AttendanceCorrections $corrections) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(AttendanceCorrectionStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->corrections->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(AttendanceCorrectionResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->corrections->counts(),
            'status_labels' => AttendanceCorrections::STATUS_LABELS,
        ]);
    }

    public function store(Request $request, string $workDay): JsonResponse
    {
        $data = $request->validate([
            'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'status' => ['nullable', 'in:valide,rejete'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['reason.required' => 'Motivez la correction.', 'reason.min' => 'Le motif est trop court.', 'worked_minutes.max' => 'La durée dépasse 24 heures.']);

        $day = AttendanceController::find($workDay);
        $this->corrections->request($day, $data['worked_minutes'] ?? null, $data['status'] ?? null, trim($data['reason']), $request->user(), $request);

        return ApiResponse::success(null, 'Correction demandée : une autre personne doit l’approuver.');
    }

    public function approve(Request $request, string $correction): JsonResponse
    {
        $this->corrections->approve($this->find($correction), $request->user(), $request);

        return ApiResponse::success(null, 'Correction approuvée.');
    }

    public function refuse(Request $request, string $correction): JsonResponse
    {
        $this->corrections->refuse($this->find($correction), $request->user(), $request);

        return ApiResponse::success(null, 'Correction refusée.');
    }

    private function find(string $id): AttendanceCorrection
    {
        return AttendanceCorrection::query()->with('workDay')->whereNotNull('work_day_id')->findOrFail($id);
    }
}
