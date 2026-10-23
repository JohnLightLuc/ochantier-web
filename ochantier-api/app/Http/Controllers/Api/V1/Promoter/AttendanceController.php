<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Enums\AttendanceEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\AttendanceAbsenceRequest;
use App\Http\Requests\V1\Promoter\AttendanceEventRequest;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\SiteAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Pointage des ouvriers » du détail chantier : feuille du jour, arrivées et départs, absences, validation de la
 * journée et historique. Chaque action renvoie la feuille de la journée à jour.
 */
class AttendanceController extends Controller
{
    public function __construct(private SiteAttendance $attendance) {}

    /**
     * Feuille de pointage d’une journée (date=AAAA-MM-JJ, aujourd’hui par défaut).
     */
    public function index(Request $request, Site $site): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']], ['date.date_format' => 'Date invalide (AAAA-MM-JJ).']);

        return response()->json(['data' => $this->attendance->day($site, $this->date($request->query('date')))]);
    }

    public function history(Site $site): JsonResponse
    {
        return response()->json($this->attendance->history($site));
    }

    /**
     * Arrivée ou départ. Un envoi rejoué (même client_uuid) renvoie 200 sans créer de doublon.
     */
    public function storeEvent(AttendanceEventRequest $request, Site $site): JsonResponse
    {
        [$event, $created] = $this->attendance->record($site, $request->user(), $request->validated(), $request->file('photo'), $request->file('signature'));
        $time = CarbonImmutable::parse($event->device_recorded_at ?? $event->recorded_at)->setTimezone(config('app.timezone'))->format('H:i');

        return ApiResponse::success(
            $this->attendance->day($site, CarbonImmutable::parse($event->work_date)),
            ($event->event_type === AttendanceEventType::Arrivee ? 'Arrivée validée' : 'Départ validé').' à '.$time,
            $created ? 201 : 200,
            ['event_id' => $event->id],
        );
    }

    public function storeAbsence(AttendanceAbsenceRequest $request, Site $site): JsonResponse
    {
        $date = $this->date($request->input('date'));
        $day = $this->attendance->markAbsent($site, $request->user(), $request->input('assignment_id'), $date);

        return response()->json([
            'data' => $this->attendance->day($site, $date),
            'message' => $this->attendance->name($day->assignment).' est marqué absent.',
        ]);
    }

    /**
     * « Valider la journée » : chaque ouvrier a son départ ou son absence.
     */
    public function validateDay(Request $request, Site $site, string $date): JsonResponse
    {
        $day = $this->date($date);

        return response()->json([
            'data' => $this->attendance->validateDay($site, $request->user(), $day),
            'message' => 'Journée du '.$day->locale('fr')->translatedFormat('j M').' validée',
        ]);
    }

    private function date(?string $date): CarbonImmutable
    {
        return $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : CarbonImmutable::today();
    }
}
