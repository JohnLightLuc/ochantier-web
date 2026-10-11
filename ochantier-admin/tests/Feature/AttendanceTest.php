<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use App\Support\AttendanceCalculator as Calc;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function calc(Attendance $p): array
    {
        return Calc::calculate($p->fresh()->load('worker', 'site', 'anomalies', 'corrections'));
    }

    public function test_durations_lateness_overtime_and_status_are_computed(): void
    {
        $day = Attendance::factory()->create();
        $c = $this->calc($day);
        $this->assertSame(510, $c['net'] + 0);
        $this->assertSame('8 h 30', Calc::duration($c['net']));
        $this->assertSame('Journée complète', $c['status']);
        $this->assertTrue($c['closable']);
        $this->assertSame(['Journée non clôturée'], $c['incomplete']);

        $late = Attendance::factory()->state(fn (array $a) => ['arrival' => [...$a['arrival'], 'time' => '07:55'], 'departure' => [...$a['departure'], 'time' => '18:30']])->create();
        $c = $this->calc($late);
        $this->assertTrue($c['lateFlag']);
        $this->assertSame(25, $c['late']);
        $this->assertSame(65, $c['overtime']);
        $this->assertSame(['Retard', 'Heures supplémentaires'], $c['indicators']);

        $open = Attendance::factory()->arrivedOnly()->create();
        $c = $this->calc($open);
        $this->assertSame('Journée incomplète', $c['status']);
        $this->assertContains('Arrivée validée sans départ', $c['incomplete']);
        $this->assertSame(['Départ non validé'], $c['blockers']);
        $this->assertFalse($c['closable']);
    }

    public function test_night_shift_crosses_midnight(): void
    {
        $night = Attendance::factory()->state(fn (array $a) => [
            'night' => true, 'planned_start' => '19:00', 'planned_end' => '04:00', 'pauses' => [],
            'arrival' => [...$a['arrival'], 'time' => '19:00'], 'departure' => [...$a['departure'], 'time' => '04:00'],
        ])->create();

        $this->assertSame(540, $this->calc($night)['net']);
    }

    public function test_list_page_filters_period_and_shortcuts(): void
    {
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class]);

        $this->get('/pointages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Index')
                ->where('period.key', 'jour')
                ->where('period.label', '23/09/2026')
                ->where('shortcuts.0.key', 'att')
                ->where('shortcuts.0.count', 10)
                ->has('attendances.data', 8)
                ->where('attendances.total', 10)
                ->has('filterDefs', 5)
            );

        $this->get('/pointages?periode=mois&raccourci=anom')->assertInertia(fn (Assert $page) => $page
            ->where('period.shortcut', 'anom')
            ->where('attendances.data.0.status.label', fn (string $s): bool => $s !== '')
            ->where('attendances.total', fn (int $n): bool => $n > 0)
        );
        $this->get('/pointages?periode=semaine&plus=1')->assertInertia(fn (Assert $page) => $page->has('filterDefs', 17));
        $worker = Attendance::query()->with('worker')->first()->worker;
        $this->get('/pointages?periode=mois&q='.urlencode($worker->code))->assertInertia(fn (Assert $page) => $page->where('attendances.data.0.worker.code', $worker->code));
        $this->get('/pointages/aujourdhui?periode=mois')->assertInertia(fn (Assert $page) => $page->where('period.key', 'jour')->where('header.crumbs.1.label', 'Aujourd’hui'));
    }

    public function test_export_and_detail_tabs(): void
    {
        $day = Attendance::factory()->create();

        $csv = $this->get('/pointages/export?refs='.$day->reference)->assertOk()->streamedContent();
        $this->assertStringContainsString($day->reference, $csv);
        $this->assertStringContainsString('8 h 30', $csv);

        $this->get("/pointages/{$day->reference}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Show')
                ->where('calc.labels.net', '8 h 30')
                ->where('calc.closable', true)
                ->has('tabs', 9)
                ->where('header.crumbs.1.label', $day->reference)
            );
        foreach (['arrivee', 'depart', 'temps', 'preuves', 'anomalies', 'corrections', 'impact-financier', 'historique'] as $tab) {
            $this->get("/pointages/{$day->reference}/{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab));
        }
        $this->get("/pointages/{$day->reference}/inconnu")->assertNotFound();
        $this->get('/pointages/PT-2026-0001')->assertNotFound();
    }

    public function test_correction_is_requested_then_applied_and_reopens_a_closed_day(): void
    {
        $day = Attendance::factory()->closed()->create();
        $base = "/pointages/{$day->reference}";

        $this->post("{$base}/corrections", ['field' => 'Heure de départ', 'new_value' => '18h', 'motif' => 'Oubli de pointage'])->assertSessionHasErrors('new_value');
        $this->post("{$base}/corrections", ['field' => 'Heure de départ', 'new_value' => '17:45', 'motif' => 'Oubli de pointage'])->assertSessionHasNoErrors();
        $correction = $day->corrections()->firstOrFail();
        $this->assertSame('17:00', $correction->old_value);
        $this->assertSame('Correction demandée', $day->fresh()->departure['status']);

        $this->post("{$base}/corrections/{$correction->id}/approbation")->assertSessionHasNoErrors();
        $day = $day->fresh();
        $this->assertSame('17:45', $day->departure['time']);
        $this->assertSame('Corrigé', $day->departure['status']);
        $this->assertSame(Attendance::REOPENED, $day->closure['status']);
        $this->assertSame('Durée nette 8 h 30 → 9 h 15 · HS 0 h 45', $correction->fresh()->impact);
        $this->assertSame('Corrigée', $this->calc($day)['status']);
        $this->post("{$base}/corrections/{$correction->id}/approbation")->assertStatus(422);
    }

    public function test_correction_rejection_restores_the_validated_status(): void
    {
        $day = Attendance::factory()->create();
        $base = "/pointages/{$day->reference}";
        $this->post("{$base}/corrections", ['field' => 'Heure d’arrivée', 'new_value' => '07:10', 'motif' => 'Erreur de saisie']);
        $correction = $day->corrections()->firstOrFail();

        $this->post("{$base}/corrections/{$correction->id}/rejet", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("{$base}/corrections/{$correction->id}/rejet", ['reason' => 'Heure confirmée par le promoteur'])->assertSessionHasNoErrors();
        $this->assertSame('Validée', $day->fresh()->arrival['status']);
        $this->assertSame('07:30', $day->fresh()->arrival['time']);
    }

    public function test_blocking_anomaly_prevents_closure_until_treated(): void
    {
        $day = Attendance::factory()->create();
        $base = "/pointages/{$day->reference}";

        $this->post("{$base}/anomalies", ['type' => 'Pointage hors zone', 'level' => 'Élevée', 'note' => 'Relevé à 800 m'])->assertSessionHasNoErrors();
        $this->assertSame('En anomalie', $this->calc($day)['status']);
        $this->post('/pointages/cloture', ['refs' => [$day->reference]])->assertStatus(422);

        $anomaly = $day->anomalies()->firstOrFail();
        $this->post("{$base}/anomalies/{$anomaly->id}/statut", ['status' => 'Fausse alerte'])->assertSessionHasErrors('reason');
        $this->post("{$base}/anomalies/{$anomaly->id}/statut", ['status' => 'Fausse alerte', 'reason' => 'GPS imprécis ce jour-là'])->assertSessionHasNoErrors();
        $this->post('/pointages/cloture', ['refs' => [$day->reference]])->assertSessionHasNoErrors();
        $this->assertSame('Clôturée manuellement', $day->fresh()->closure['status']);
    }

    public function test_exceptional_closure_reopening_and_transmission(): void
    {
        $day = Attendance::factory()->arrivedOnly()->create();
        $base = "/pointages/{$day->reference}";

        $this->post("{$base}/transmission")->assertStatus(422);
        $this->post('/pointages/cloture', ['refs' => [$day->reference], 'exceptionnelle' => 1, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/pointages/cloture', ['refs' => [$day->reference], 'exceptionnelle' => 1, 'reason' => 'Départ confirmé par téléphone'])->assertSessionHasNoErrors();
        $this->assertTrue($day->fresh()->isClosed());

        $this->post("{$base}/transmission")->assertSessionHasNoErrors();
        $this->assertSame(Attendance::TRANSMITTED, $day->fresh()->transmission);
        $this->post("{$base}/transmission")->assertStatus(422);

        $this->post("{$base}/reouverture", ['reason' => 'Contestation du promoteur'])->assertSessionHasNoErrors();
        $this->assertSame(Attendance::REOPENED, $day->fresh()->closure['status']);
        $this->assertSame(Attendance::NOT_TRANSMITTED, $day->fresh()->transmission);
        $this->assertDatabaseHas('attendance_activities', ['attendance_id' => $day->id, 'action' => 'a rouvert la journée']);
    }

    public function test_reminders_notes_proofs_decisions_and_regularization(): void
    {
        $day = Attendance::factory()->state(['lateness' => ['status' => 'Justification attendue', 'motif' => null]])->create();
        $base = "/pointages/{$day->reference}";

        $this->post('/pointages/relance', ['refs' => [$day->reference]])->assertSessionHasNoErrors();
        $this->assertSame(1, $day->fresh()->reminders);
        $this->post("{$base}/notes", ['note' => 'Vérifier avec le chef de chantier'])->assertSessionHasNoErrors();
        $this->assertSame('Vérifier avec le chef de chantier', $day->fresh()->notes[0]['text']);

        $this->post("{$base}/preuves/arrivee/a1/verification")->assertSessionHasNoErrors();
        $this->assertSame('Vérifiée', $day->fresh()->arrival['proofs'][0]['status']);
        $this->post("{$base}/preuves/arrivee/a2/rejet", ['reason' => 'Signature illisible'])->assertSessionHasNoErrors();
        $this->assertTrue($this->calc($day)['proofMissing']);
        $this->post("{$base}/preuves/arrivee/zz/verification")->assertNotFound();

        $this->post("{$base}/decisions/lateness", ['status' => 'Justifié', 'reason' => 'Embouteillage confirmé'])->assertSessionHasNoErrors();
        $this->assertSame('Justifié', $day->fresh()->lateness['status']);
        $this->post("{$base}/decisions/overtime", ['status' => 'Validées', 'reason' => 'Aucune'])->assertNotFound();

        $this->post("{$base}/regularisation", ['object' => 'Retard', 'decision' => 'Régularisé', 'reason' => 'Accord de la direction'])->assertSessionHasNoErrors();
        $this->assertSame('Régularisé', $day->fresh()->lateness['status']);
    }
}
