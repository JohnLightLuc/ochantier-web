<?php

namespace App\Http\Controllers\Api\V1\Admin\Report;

use App\Enums\ExportFormat;
use App\Enums\ReportScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Report\ReportScheduleRequest;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\ReportCatalog;
use App\Support\Admin\ReportLibrary;
use App\Support\ApiResponse;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bibliothèque des rapports et programmations.
 */
class ReportLibraryController extends Controller
{
    public function __construct(private ReportLibrary $library, private ReportCatalog $catalog, private MediaStore $media) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $kinds = array_values(array_filter(array_keys(ReportCatalog::KINDS), fn (string $kind) => $this->catalog->allowed($kind, $request->user())));
        $rows = ReportRun::query()->whereIn('report_kind', $kinds)->with('generatedByAdmin')->orderByDesc('generated_at')->orderBy('id')->paginate((int) $request->input('per_page', 20))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (ReportRun $run) => [
            'id' => $run->id,
            'kind' => $run->report_kind,
            'name' => $run->name,
            'format' => $run->format->value,
            'row_count' => $run->row_count,
            'by' => $run->generatedByAdmin?->fullName() ?? 'Programmation',
            'generated_at' => $run->generated_at?->toIso8601String(),
        ])->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'kinds' => collect($kinds)->map(fn (string $kind) => ['value' => $kind, 'label' => ReportCatalog::KINDS[$kind]])->all(),
            'schedules' => ReportSchedule::query()->whereIn('report_kind', $kinds)->orderBy('next_run_at')->get()->map(fn (ReportSchedule $schedule) => $this->schedule($schedule))->values()->all(),
        ]);
    }

    public function generate(Request $request, string $kind): JsonResponse
    {
        abort_unless($this->catalog->allowed($kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ]);

        $run = $this->library->generate($kind, CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), ExportFormat::from($data['format']), $request->user());
        AdminAudit::record($request->user(), 'reports', 'reports.generated', $request, $run, null, ['kind' => $kind, 'format' => $data['format']]);

        return ApiResponse::success(['id' => $run->id, 'name' => $run->name], 'Rapport ajouté à la bibliothèque.', 201);
    }

    public function download(Request $request, string $runId): StreamedResponse
    {
        $run = ReportRun::query()->with('mediaFile')->findOrFail($runId);
        abort_unless($this->catalog->allowed($run->report_kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        AdminAudit::record($request->user(), 'reports', 'reports.downloaded', $request, $run);

        return $this->media->response($run->mediaFile);
    }

    public function storeSchedule(ReportScheduleRequest $request): JsonResponse
    {
        abort_unless($this->catalog->allowed($request->input('kind'), $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        $schedule = $this->library->schedule($request->validated(), $request->user());
        AdminAudit::record($request->user(), 'reports', 'reports.scheduled', $request, $schedule, null, ['kind' => $schedule->report_kind, 'frequency' => $schedule->frequency->value, 'recipients' => $schedule->recipients]);

        return ApiResponse::success($this->schedule($schedule), 'Rapport programmé.', 201);
    }

    public function toggleSchedule(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = ReportSchedule::query()->findOrFail($scheduleId);
        abort_unless($this->catalog->allowed($schedule->report_kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        $active = $schedule->status === ReportScheduleStatus::Actif;
        $schedule->update(['status' => $active ? ReportScheduleStatus::Suspendu : ReportScheduleStatus::Actif]);
        AdminAudit::record($request->user(), 'reports', 'reports.schedule_toggled', $request, $schedule, null, ['status' => $schedule->status->value]);

        return ApiResponse::success($this->schedule($schedule), $active ? 'Programmation suspendue.' : 'Programmation réactivée.');
    }

    public function destroySchedule(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = ReportSchedule::query()->findOrFail($scheduleId);
        abort_unless($this->catalog->allowed($schedule->report_kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        ReportRun::query()->where('schedule_id', $schedule->getKey())->update(['schedule_id' => null]);
        AdminAudit::record($request->user(), 'reports', 'reports.schedule_deleted', $request, $schedule, ['name' => $schedule->name]);
        $schedule->delete();

        return ApiResponse::success(null, 'Programmation supprimée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'kind' => $schedule->report_kind,
            'name' => $schedule->name,
            'frequency' => ['value' => $schedule->frequency->value, 'label' => $schedule->frequency->label()],
            'format' => $schedule->format->value,
            'recipients' => $schedule->recipients,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'status' => ['value' => $schedule->status->value, 'label' => $schedule->status->label()],
        ];
    }
}
