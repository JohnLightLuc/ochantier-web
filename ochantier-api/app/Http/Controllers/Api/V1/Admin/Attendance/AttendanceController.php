<?php

namespace App\Http\Controllers\Api\V1\Admin\Attendance;

use App\Enums\WorkDayStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Attendance\AttendanceIndexRequest;
use App\Http\Resources\V1\Admin\AttendanceDetailResource;
use App\Http\Resources\V1\Admin\AttendanceListResource;
use App\Models\WorkDay;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\AttendanceDirectory;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pointages du Back Office : liste, export, fiche d’une journée et relance du promoteur. La validation d’une journée
 * reste celle du promoteur.
 */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceDirectory $directory, private PromoterNotifier $notifier) {}

    public function index(AttendanceIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $days = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(AttendanceListResource::collection($days->items())->resolve($request), meta: [
            'current_page' => $days->currentPage(),
            'last_page' => $days->lastPage(),
            'per_page' => $days->perPage(),
            'total' => $days->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    public function export(AttendanceIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = AttendanceListResource::collection($this->directory->query($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'attendance', 'attendance.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Ouvrier', 'Référence', 'Chantier', 'Promoteur', 'Métier', 'Arrivée', 'Départ', 'Minutes', 'Hors zone', 'Anomalies ouvertes', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['worker']['full_name'], $row['worker']['reference'], $row['site']['label'], $row['promoter']['name'], $row['trade'], $row['arrival'], $row['departure'], $row['worked_minutes'], $row['out_of_zone'] ? 'Oui' : 'Non', $row['open_anomalies'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'pointages-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $workDay): JsonResponse
    {
        return ApiResponse::success(AttendanceDetailResource::make(self::find($workDay))->resolve($request));
    }

    /**
     * Rappelle au promoteur de valider ce jour-là, une fois par jour et par chantier.
     */
    public function remind(Request $request, string $workDay): JsonResponse
    {
        $day = self::find($workDay);

        if ($day->status !== WorkDayStatus::AValider) {
            throw ValidationException::withMessages(['attendance' => 'Cette journée n’attend pas la validation du promoteur.']);
        }

        $sent = $this->notifier->attendanceReminder($day->mission->site, $day->work_date->toImmutable());

        if ($sent === 0) {
            throw ValidationException::withMessages(['attendance' => 'Le promoteur a déjà été relancé aujourd’hui pour cette journée.']);
        }

        AdminAudit::record($request->user(), 'attendance', 'attendance.reminded', $request, $day, null, ['site_id' => $day->mission->site->id, 'date' => $day->work_date->toDateString()]);

        return ApiResponse::success(AttendanceDetailResource::make(self::find($workDay))->resolve($request), 'Promoteur relancé.');
    }

    public static function find(string $id): WorkDay
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(AttendanceDirectory::class)->query([])->where('work_days.id', $id)->firstOrFail();
    }
}
