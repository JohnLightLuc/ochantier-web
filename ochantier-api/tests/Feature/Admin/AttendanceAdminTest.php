<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
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

    public function test_the_routes_need_their_permissions(): void
    {
        $day = $this->pointDay();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/attendance')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create())->getJson('/api/v1/admin/attendance')->assertOk();
        $this->postJson("/api/v1/admin/attendance/{$day->id}/remind")->assertForbidden();
    }
}
