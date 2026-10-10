<?php

namespace Tests\Feature;

use App\Enums\AvailabilityType;
use App\Enums\WorkerAvailability;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_period_can_be_declared_and_removed(): void
    {
        $worker = Worker::factory()->create();

        $this->post("/ouvriers/{$worker->slug}/periodes", ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-05', 'type' => 'rest'])->assertSessionHasErrors('ends_on');
        $this->post("/ouvriers/{$worker->slug}/periodes", ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'type' => 'rest', 'reason' => 'Congés'])->assertRedirect();

        $period = $worker->periods()->first();
        $this->assertSame(AvailabilityType::Rest, $period->type);

        $this->delete("/ouvriers/{$worker->slug}/periodes/{$period->id}")->assertRedirect();
        $this->assertSame(0, $worker->periods()->count());
    }

    public function test_unavailability_on_an_assignment_opens_a_replacement(): void
    {
        $worker = Worker::factory()->withDemo(['assignments' => [['id' => 'a1', 'site' => 'Tour Horizon', 'promoter' => 'Ivoire Properties', 'offer' => 'Renfort', 'trade' => 'Maçon', 'type' => 'Ponctuelle', 'start' => '2026-10-01', 'end' => '2026-10-30', 'pay' => 'Journalier', 'amount' => 10000, 'status' => 'En cours', 'progress' => 10]]])->create();

        $this->post("/ouvriers/{$worker->slug}/indisponibilite", [
            'moment' => 'Pendant l’exécution du chantier', 'assignment' => 'a1', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-20',
            'reason' => 'Maladie', 'urgency' => 'Élevée', 'comment' => 'Paludisme, arrêt médical.',
        ])->assertRedirect("/ouvriers/{$worker->slug}/remplacements");

        $worker->refresh();
        $this->assertSame(WorkerAvailability::Unavailable, $worker->availability);
        $this->assertSame('REP-0043', $worker->demo('replacements')[0]['id']);
        $this->assertSame('Remplacement demandé', $worker->demo('assignments')[0]['status']);
    }

    public function test_unavailability_without_assignment_only_records_the_period(): void
    {
        $worker = Worker::factory()->create();

        $this->post("/ouvriers/{$worker->slug}/indisponibilite", [
            'moment' => 'Avant le début du chantier', 'assignment' => '', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13',
            'reason' => 'Raison familiale', 'urgency' => 'Faible', 'comment' => 'Mariage.',
        ])->assertRedirect();

        $this->assertSame(1, $worker->periods()->count());
        $this->assertSame([], $worker->fresh()->demo('replacements'));
    }
}
