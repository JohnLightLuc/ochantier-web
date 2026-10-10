<?php

namespace Tests\Feature;

use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_profile_under_review_can_be_validated_or_rejected(): void
    {
        $worker = Worker::factory()->toReview()->create(['availability' => WorkerAvailability::Unavailable]);

        $this->post("/ouvriers/{$worker->slug}/rejet", ['reason' => 'Autre', 'request' => 'Compléter le profil', 'comment' => 'court'])->assertSessionHasErrors('comment');
        $this->post("/ouvriers/{$worker->slug}/rejet", ['reason' => 'Doublon potentiel', 'request' => 'Compléter le profil', 'comment' => 'Même numéro qu’un autre profil.'])->assertRedirect();
        $this->assertSame(WorkerStatus::Rejected, $worker->fresh()->status);
        $this->assertSame('Doublon potentiel', $worker->fresh()->rejection['reason']);

        $this->post("/ouvriers/{$worker->slug}/validation")->assertRedirect();
        $worker->refresh();
        $this->assertSame(WorkerStatus::Validated, $worker->status);
        $this->assertNull($worker->rejection);
        $this->assertSame(WorkerAvailability::Available, $worker->availability);
    }

    public function test_validated_profile_cannot_be_validated_again(): void
    {
        $worker = Worker::factory()->create();

        $this->post("/ouvriers/{$worker->slug}/validation")->assertStatus(422);
    }

    public function test_suspension_keeps_and_restores_availability(): void
    {
        $worker = Worker::factory()->create(['availability' => WorkerAvailability::OnMission]);

        $this->post("/ouvriers/{$worker->slug}/suspension", ['category' => 'Absences répétées', 'reason' => 'Trois absences non justifiées.'])->assertRedirect();
        $worker->refresh();
        $this->assertSame(WorkerStatus::Suspended, $worker->status);
        $this->assertSame(WorkerAvailability::Unavailable, $worker->availability);

        $this->post("/ouvriers/{$worker->slug}/reactivation")->assertRedirect();
        $worker->refresh();
        $this->assertSame(WorkerStatus::Validated, $worker->status);
        $this->assertSame(WorkerAvailability::OnMission, $worker->availability);
    }

    public function test_archive_and_bulk_validation(): void
    {
        $worker = Worker::factory()->create();
        $this->post("/ouvriers/{$worker->slug}/archivage")->assertRedirect();
        $this->assertSame(WorkerStatus::Archived, $worker->fresh()->status);

        $pending = Worker::factory()->toReview()->count(2)->create();
        $this->post('/ouvriers/validation-groupee', ['ids' => [...$pending->pluck('id'), $worker->id]])->assertInertiaFlash('toast', '2 profils validés.');
        $this->assertSame(WorkerStatus::Archived, $worker->fresh()->status);
    }
}
