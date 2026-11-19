<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Enums\AttendanceEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\AttendanceCorrectionRequest;
use App\Http\Requests\V1\Promoter\AttendanceAbsenceRequest;
use App\Http\Requests\V1\Promoter\AttendanceEventRequest;
use App\Models\Assignment;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorAttendance;
use App\Support\Contractor\ContractorSites;
use App\Support\Contractor\ContractorSubscriptions;
use App\Support\Promoter\SiteAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onglet « Pointage » d’un chantier attribué : feuille du jour, arrivée ou départ (position, photo, signature),
 * absence, validation de la journée, historique, fiche d’une journée et correction motivée. Chaque action renvoie
 * la feuille de la journée à jour.
 */
class AttendanceController extends Controller
{
    public function __construct(private SiteAttendance $attendance, private ContractorAttendance $contractorAttendance, private ContractorSites $sites, private ContractorSubscriptions $subscriptions) {}

    /**
     * Feuille de pointage d’une journée (date=AAAA-MM-JJ, aujourd’hui par défaut).
     */
    public function index(Request $request, string $contractorSite): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']], ['date.date_format' => 'Date invalide (AAAA-MM-JJ).']);

        return ApiResponse::success($this->attendance->day($this->site($request, $contractorSite), $this->date($request->query('date'))));
    }

    public function history(Request $request, string $contractorSite): JsonResponse
    {
        $history = $this->attendance->history($this->site($request, $contractorSite));

        return ApiResponse::success($history['data'], meta: $history['meta']);
    }

    /**
     * Arrivée ou départ. Un envoi rejoué (même client_uuid) renvoie 200 sans créer de doublon.
     */
    public function storeEvent(AttendanceEventRequest $request, string $contractorSite): JsonResponse
    {
        $this->subscriptions->ensureSubscribed(ProfileController::contractor($request), 'Pointage');
        $site = $this->site($request, $contractorSite);
        $this->ensureTeam($request, $site, $request->validated('assignment_id'));
        [$event, $created] = $this->attendance->record($site, $request->user(), $request->validated(), $request->file('photo'), $request->file('signature'));
        $time = CarbonImmutable::parse($event->device_recorded_at ?? $event->recorded_at)->setTimezone(config('app.timezone'))->format('H:i');

        return ApiResponse::success(
            $this->attendance->day($site, CarbonImmutable::parse($event->work_date)),
            ($event->event_type === AttendanceEventType::Arrivee ? 'Arrivée validée' : 'Départ validé').' à '.$time,
            $created ? 201 : 200,
            ['event_id' => $event->id],
        );
    }

    public function storeAbsence(AttendanceAbsenceRequest $request, string $contractorSite): JsonResponse
    {
        $this->subscriptions->ensureSubscribed(ProfileController::contractor($request), 'Pointage');
        $site = $this->site($request, $contractorSite);
        $this->ensureTeam($request, $site, (string) $request->input('assignment_id'));
        $date = $this->date($request->input('date'));
        $day = $this->attendance->markAbsent($site, $request->user(), $request->input('assignment_id'), $date);

        return ApiResponse::success($this->attendance->day($site, $date), $this->attendance->name($day->assignment).' est marqué absent.');
    }

    /**
     * « Valider la journée » : chaque ouvrier a son départ ou son absence ; la synthèse est transmise au promoteur.
     */
    public function validateDay(Request $request, string $contractorSite, string $date): JsonResponse
    {
        $this->subscriptions->ensureSubscribed(ProfileController::contractor($request), 'Pointage');
        $day = $this->date($date);

        return ApiResponse::success($this->contractorAttendance->validateDay($this->site($request, $contractorSite), $request->user(), $day),
            'Journée du '.$day->locale('fr')->translatedFormat('j M').' validée · synthèse transmise au promoteur');
    }

    public function show(Request $request, string $contractorSite, string $workDay): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->contractorAttendance->detail($this->contractorAttendance->findDay($contractor, $this->sites->find($contractor, $contractorSite), $workDay)));
    }

    public function correct(AttendanceCorrectionRequest $request, string $contractorSite, string $workDay): JsonResponse
    {
        $this->subscriptions->ensureSubscribed(ProfileController::contractor($request), 'Pointage');
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);
        $day = $this->contractorAttendance->correct($this->contractorAttendance->findDay($contractor, $site, $workDay), $request->user(), $request->validated());

        return ApiResponse::success($this->contractorAttendance->detail($this->contractorAttendance->findDay($contractor, $site, $day->id)), 'Pointage corrigé. La valeur précédente est conservée dans l’historique.');
    }

    private function site(Request $request, string $id): Site
    {
        return $this->sites->find(ProfileController::contractor($request), $id);
    }

    /**
     * L’ouvrier pointé fait partie de l’équipe de l’entrepreneur.
     */
    private function ensureTeam(Request $request, Site $site, string $assignmentId): void
    {
        $mine = str($assignmentId)->isUuid() && Assignment::query()->whereKey($assignmentId)
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->where('contractor_id', ProfileController::contractor($request)->getKey()))
            ->exists();

        abort_unless($mine, 404, 'Cet ouvrier ne fait pas partie de votre équipe sur ce chantier.');
    }

    private function date(?string $date): CarbonImmutable
    {
        return $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : CarbonImmutable::today();
    }
}
