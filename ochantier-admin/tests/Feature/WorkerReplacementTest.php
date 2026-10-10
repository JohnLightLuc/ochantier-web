<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkerSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function case(): array
    {
        return Worker::firstWhere('slug', 'siaka-bamba')->demo('replacements')[0];
    }

    public function test_replacement_workflow_runs_to_closure(): void
    {
        $url = '/ouvriers/siaka-bamba/remplacements/REP-0042';

        $this->post("{$url}/analyser")->assertStatus(422);
        $this->post("{$url}/candidat", ['candidate' => 'paul-ake'])->assertStatus(422);
        $this->post("{$url}/candidat", ['candidate' => 'koffi-adjoumani'])->assertRedirect();
        $this->assertSame('Koffi Adjoumani', $this->case()['chosen']['name']);

        $this->post("{$url}/refus", ['reason' => 'Ouvrier déjà réservé ailleurs.'])->assertRedirect();
        $this->assertSame(4, $this->case()['step']);

        $this->post("{$url}/candidat", ['candidate' => 'koffi-adjoumani']);
        foreach (['valider', 'valider-promoteur', 'confirmer', 'transferer'] as $action) {
            $this->post("{$url}/{$action}")->assertRedirect();
        }
        $this->assertSame(9, $this->case()['step']);

        $this->post("{$url}/cloture")->assertRedirect();
        $this->assertSame('Clôturé', $this->case()['status']);

        $assignment = collect(Worker::firstWhere('slug', 'siaka-bamba')->demo('assignments'))->firstWhere('id', $this->case()['assignmentId']);
        $this->assertSame('Remplacée', $assignment['status']);
        $this->post("{$url}/annulation")->assertStatus(422);
    }

    public function test_replacement_can_be_cancelled(): void
    {
        $this->post('/ouvriers/siaka-bamba/remplacements/REP-0042/annulation')->assertRedirect();

        $this->assertSame('Annulé', $this->case()['status']);
        $assignment = collect(Worker::firstWhere('slug', 'siaka-bamba')->demo('assignments'))->firstWhere('id', $this->case()['assignmentId']);
        $this->assertSame('En cours', $assignment['status']);
    }
}
