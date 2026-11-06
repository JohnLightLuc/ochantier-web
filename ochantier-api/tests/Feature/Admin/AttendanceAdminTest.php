<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\WorkDay;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Pointages : liste, export, fiche et relance du promoteur.
 */
class AttendanceAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * Une journée : Kouamé complet, Touré parti sans départ pointé, Serge absent.
     */
    private function pointDay(): WorkDay
    {
        $this->confirmSite();
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertSuccessful();
        $this->onWorkDay(0, '17:00');
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'))->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertSuccessful();

        return WorkDay::query()->where('worker_id', $this->assignmentOf('Kouamé Koffi')->worker_id)->sole();
    }

    public function test_the_list_shows_one_line_per_worker_and_day(): void
    {
        $this->pointDay();
        $date = WorkDay::query()->firstOrFail()->work_date->toDateString();

        $this->asAdmin()->getJson('/api/v1/admin/attendance')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.counts.statuses.a_valider', 1)
            ->assertJsonPath('meta.counts.statuses.absent', 1)
            ->assertJsonPath('meta.counts.todo.absences', 1);

        foreach (['status=a_valider', 'search=Kouamé', 'todo=retard', 'todo=incomplets', 'todo=hors_zone', 'todo=anomalies', 'sort=worker', 'sort=minutes&direction=desc', 'sort=status', "date_from={$date}&date_to={$date}", 'site_id='.$this->site->id] as $query) {
            $this->getJson('/api/v1/admin/attendance?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/attendance?search=Kouamé')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status.value', 'a_valider')->assertJsonPath('data.0.departure', '17:00');
        $this->getJson('/api/v1/admin/attendance?todo=absences')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.worker.full_name', 'Serge Aka');
        $this->getJson('/api/v1/admin/attendance?todo=anomalies')->assertJsonPath('data.0.open_anomalies', 1);
        $this->getJson('/api/v1/admin/attendance?date_from=2000-01-01&date_to=2000-01-02')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/attendance?todo=inconnu')->assertJsonValidationErrors(['todo']);

        $csv = $this->get('/api/v1/admin/attendance/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Kouamé Koffi', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'attendance.exported')->exists());
    }

    public function test_a_late_day_lands_in_the_late_lists(): void
    {
        $this->pointDay();
        $this->onWorkDay(1);

        $this->asAdmin()->getJson('/api/v1/admin/attendance?todo=retard')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/attendance?todo=incomplets')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.worker.full_name', 'Touré Ali');
    }

    public function test_the_fiche_shows_the_proofs_and_the_promoter_can_be_reminded_once_a_day(): void
    {
        $day = $this->pointDay();

        $this->asAdmin()->getJson("/api/v1/admin/attendance/{$day->id}")->assertOk()
            ->assertJsonPath('data.can.remind', true)
            ->assertJsonPath('data.events.arrival.status.value', 'soumis')
            ->assertJsonPath('data.events.departure.time', '17:00')
            ->assertJsonPath('data.validated_at', null);

        $this->postJson("/api/v1/admin/attendance/{$day->id}/remind")->assertOk()->assertJsonPath('message', 'Promoteur relancé.');
        $this->assertTrue(Notification::query()->where('event_code', 'attendance.pending')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'attendance.reminded')->exists());
        $this->postJson("/api/v1/admin/attendance/{$day->id}/remind")->assertJsonValidationErrors(['attendance']);

        $absent = WorkDay::query()->where('status', 'absent')->sole();
        $this->postJson("/api/v1/admin/attendance/{$absent->id}/remind")->assertJsonValidationErrors(['attendance']);
    }

    public function test_an_anomaly_is_reported_then_closed_in_writing(): void
    {
        $day = $this->pointDay();
        $url = "/api/v1/admin/attendance/{$day->id}/anomalies";

        $this->asAdmin()->postJson($url, ['type' => 'absence', 'comment' => 'Pas ce type-là.'])->assertJsonValidationErrors(['type']);
        $this->postJson($url, ['type' => 'doublon', 'comment' => 'Deux pointages identiques.'])->assertOk();
        $this->postJson($url, ['type' => 'doublon'])->assertJsonValidationErrors(['comment']);

        $response = $this->getJson('/api/v1/admin/attendance/anomalies?type=doublon')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.ouverte', 2)
            ->assertJsonPath('data.0.detected_by.value', 'admin')
            ->assertJsonPath('data.0.work_day_id', $day->id)
            ->assertJsonPath('data.0.is_open', true);
        $id = $response->json('data.0.id');

        foreach (['status=ouverte', 'search=Kouamé', 'search=Palmiers'] as $query) {
            $this->getJson('/api/v1/admin/attendance/anomalies?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/attendance/anomalies?search=Kouamé')->assertJsonPath('meta.total', 1);

        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/start")->assertOk();
        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/start")->assertJsonValidationErrors(['anomaly']);
        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/close", ['outcome' => 'ouverte', 'resolution' => 'Sans objet.'])->assertJsonValidationErrors(['outcome']);
        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/close", ['outcome' => 'rejetee'])->assertJsonValidationErrors(['resolution']);
        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/close", ['outcome' => 'rejetee', 'resolution' => 'Vérifié avec le promoteur.'])->assertOk();
        $this->postJson("/api/v1/admin/attendance/anomalies/{$id}/close", ['outcome' => 'resolue', 'resolution' => 'Une deuxième fois.'])->assertJsonValidationErrors(['anomaly']);

        $this->getJson("/api/v1/admin/attendance/{$day->id}")->assertJsonPath('data.anomalies.0.status.value', 'rejetee')->assertJsonPath('data.anomalies.0.resolution', 'Vérifié avec le promoteur.')->assertJsonPath('data.anomalies.0.is_open', false);
        $this->assertTrue(AuditLog::query()->where('action', 'attendance.anomaly_closed')->exists());
    }

    /**
     * La journée de Kouamé, validée par le promoteur.
     */
    private function validatedDay(): WorkDay
    {
        $day = $this->pointDay();
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$day->work_date->toDateString()}/validate")->assertOk();

        return $day->fresh();
    }

    public function test_a_correction_needs_a_second_person(): void
    {
        $day = $this->validatedDay();
        $mission = $day->mission;
        $this->assertSame(1, $mission->fresh()->validated_days);
        $requester = Admin::factory()->role('operations')->create();
        $approver = Admin::factory()->role('operations')->create();
        $url = "/api/v1/admin/attendance/{$day->id}/corrections";

        $this->asAdmin($requester)->getJson("/api/v1/admin/attendance/{$day->id}")->assertJsonPath('data.can.correct.minutes', true)->assertJsonPath('data.can.correct.status', 'rejete');
        $this->postJson($url, ['reason' => 'Rien à changer ici.'])->assertJsonValidationErrors(['attendance']);
        $this->postJson($url, ['worked_minutes' => 300])->assertJsonValidationErrors(['reason']);
        $this->postJson($url, ['status' => 'valide', 'reason' => 'Statut identique demandé.'])->assertJsonValidationErrors(['attendance']);
        $this->postJson($url, ['worked_minutes' => 300, 'status' => 'rejete', 'reason' => 'Ouvrier reparti le matin.'])->assertOk();
        $this->postJson($url, ['worked_minutes' => 240, 'reason' => 'Deuxième demande en parallèle.'])->assertJsonValidationErrors(['attendance']);

        $id = $this->getJson('/api/v1/admin/attendance/corrections?status=demandee')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonPath('data.0.new_values.status', 'rejete')
            ->json('data.0.id');
        $this->postJson("/api/v1/admin/attendance/corrections/{$id}/approve")->assertJsonValidationErrors(['attendance']);
        $this->assertSame('valide', $day->fresh()->status->value);

        $this->asAdmin($approver)->getJson('/api/v1/admin/attendance/corrections')->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.requested_by', $requester->fullName());
        $this->postJson("/api/v1/admin/attendance/corrections/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/attendance/corrections/{$id}/refuse")->assertJsonValidationErrors(['attendance']);

        $fresh = $day->fresh();
        $this->assertSame('rejete', $fresh->status->value);
        $this->assertSame(300, $fresh->worked_minutes);
        $this->assertSame('valide', AttendanceCorrection::query()->sole()->old_values['status']);
        $this->assertSame(1, $mission->fresh()->validated_days);
        $this->assertTrue(AuditLog::query()->where('action', 'attendance.correction_approved')->exists());
        $this->getJson("/api/v1/admin/attendance/{$day->id}")->assertJsonPath('data.can.correct.status', 'valide')->assertJsonPath('data.can.correct.minutes', false);
    }

    public function test_a_correction_refused_changes_nothing_and_stale_or_locked_days_are_refused(): void
    {
        $day = $this->validatedDay();
        $url = "/api/v1/admin/attendance/{$day->id}/corrections";
        $minutes = $day->worked_minutes;

        $this->asAdmin()->postJson($url, ['worked_minutes' => 300, 'reason' => 'Durée à revoir ici.'])->assertOk();
        $first = AttendanceCorrection::query()->sole();
        $this->postJson("/api/v1/admin/attendance/corrections/{$first->id}/refuse")->assertOk();
        $this->assertSame($minutes, $day->fresh()->worked_minutes);

        $this->postJson($url, ['worked_minutes' => 300, 'reason' => 'Durée à revoir encore.'])->assertOk();
        $second = AttendanceCorrection::query()->where('status', 'demandee')->sole();
        $day->fresh()->update(['worked_minutes' => 420]);
        $this->asAdmin(Admin::factory()->role('operations')->create())->postJson("/api/v1/admin/attendance/corrections/{$second->id}/approve")->assertJsonValidationErrors(['attendance']);
        $this->postJson("/api/v1/admin/attendance/corrections/{$second->id}/refuse")->assertOk();

        $day->fresh()->update(['is_locked' => true]);
        $this->postJson($url, ['worked_minutes' => 300, 'reason' => 'Journée déjà facturée.'])->assertJsonValidationErrors(['attendance']);
        $this->getJson("/api/v1/admin/attendance/{$day->id}")->assertJsonPath('data.can.correct.minutes', false);

        $absent = WorkDay::query()->where('status', 'absent')->sole();
        $this->postJson("/api/v1/admin/attendance/{$absent->id}/corrections", ['status' => 'valide', 'reason' => 'Il était bien présent.'])->assertJsonValidationErrors(['attendance']);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $day = $this->pointDay();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/attendance')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create())->getJson('/api/v1/admin/attendance')->assertOk();
        $this->postJson("/api/v1/admin/attendance/{$day->id}/remind")->assertForbidden();
        $this->postJson("/api/v1/admin/attendance/{$day->id}/anomalies", ['type' => 'doublon', 'comment' => 'Deux lignes.'])->assertForbidden();
        $this->postJson("/api/v1/admin/attendance/{$day->id}/corrections", ['worked_minutes' => 300, 'reason' => 'Pas autorisé ici.'])->assertForbidden();
    }
}
