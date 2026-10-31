<?php

namespace Tests\Feature;

use App\Enums\SalesAgentAvailability;
use App\Enums\WorkerStatus;
use App\Models\SalesAgent;
use App\Models\User;
use App\Models\Worker;
use App\Support\SalesPerformance;
use Database\Seeders\SalesAgentSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgentPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private SalesAgent $agent;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([WorkerSeeder::class, SalesAgentSeeder::class]);
        $this->actingAs(User::factory()->create());
        $this->agent = SalesAgent::where('slug', 'mariam-kone')->firstOrFail();
        $this->base = '/commerciaux/mariam-kone';
    }

    public function test_score_is_weighted_and_correction_changes_it(): void
    {
        $performance = new SalesPerformance;
        $row = $performance->row($this->agent, '2026-08');
        $weights = config('admin.sales_score_weights');
        $expected = round(collect($weights)->map(fn (array $w, string $k): float => $row['normalized'][$k] * $w[1] / 100)->sum(), 1);
        $this->assertSame($expected, $row['score']);

        $this->post("{$this->base}/score/2026-08/correction", ['delta' => '+3', 'reason' => 'Profils validés en retard.'])->assertSessionHasErrors('authorization');
        $this->post("{$this->base}/score/2026-08/correction", ['delta' => '+3', 'reason' => 'Profils validés en retard.', 'authorization' => 'Décision DC-12'])->assertRedirect();

        $after = (new SalesPerformance)->row($this->agent->fresh(), '2026-08');
        $this->assertSame($row['metrics']['validated'] + 3, $after['metrics']['validated']);
        $this->post("{$this->base}/score/2025-01/correction", ['delta' => '1', 'reason' => 'Mois hors classement.'])->assertNotFound();
    }

    public function test_gain_is_capped_and_includes_bonuses(): void
    {
        $agent = $this->agent;
        $agent->update(['pay' => [...$agent->pay, 'cap' => 100000]]);
        $performance = new SalesPerformance;
        $row = $performance->row($agent->fresh(), '2026-08');

        $this->assertSame(100000, $performance->gain($agent->fresh(), '2026-08', $row));
        $this->assertSame(0, $performance->gain($agent, '2026-08', null));
    }

    public function test_objectives_bonus_deduction_and_payout_statuses(): void
    {
        $this->post("{$this->base}/objectifs", ['type' => 'Mensuel', 'label' => 'Passé', 'starts_on' => '2020-01-01', 'ends_on' => '2020-01-31', 'target' => '10'])->assertSessionHasErrors('starts_on');
        $this->post("{$this->base}/objectifs", ['type' => 'Mensuel', 'label' => 'Décembre 2026', 'starts_on' => today()->addMonth()->format('Y-m-d'), 'ends_on' => today()->addMonths(2)->format('Y-m-d'), 'target' => '18'])->assertRedirect();
        $objective = $this->agent->objectives()->where('label', 'Décembre 2026')->firstOrFail();
        $this->put("{$this->base}/objectifs/{$objective->id}", ['target' => '20'])->assertRedirect();
        $this->assertSame(20, $objective->fresh()->target);

        $current = $this->agent->objectives()->where('label', 'Septembre 2026')->firstOrFail();
        $this->put("{$this->base}/objectifs/{$current->id}", ['target' => '5'])->assertStatus(422);
        $this->post("{$this->base}/objectifs/{$current->id}/cloture")->assertRedirect();
        $this->assertTrue($current->fresh()->closed);

        $this->post("{$this->base}/bonus?month=2026-08", ['type' => 'Bonus Commercial du mois', 'amount' => '100000', 'reason' => 'Titre d’août.'])->assertSessionHasErrors('authorization');
        $this->post("{$this->base}/bonus?month=2026-08", ['type' => 'Bonus Commercial du mois', 'amount' => '100 000', 'reason' => 'Titre d’août.', 'authorization' => 'DC-13'])->assertRedirect();
        $this->assertSame(1, $this->agent->rewards()->count());
        $this->post("{$this->base}/retenues", ['type' => 'Profils dupliqués', 'amount' => '5000', 'reason' => 'Deux profils en double.'])->assertRedirect();
        $this->assertSame(95000, (int) $this->agent->adjustments()->sum('amount'));

        $deduction = $this->agent->adjustments()->where('amount', '<', 0)->firstOrFail();
        $ref = 'RT-'.str_pad((string) $deduction->id, 5, '0', STR_PAD_LEFT);
        $this->post("{$this->base}/gains/{$ref}/valider")->assertRedirect();
        $this->assertSame('Validé', $deduction->fresh()->status);

        $this->post("{$this->base}/gains/GC-015-202608/suspendre", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("{$this->base}/gains/GC-015-202608/suspendre", ['reason' => 'Contrôle en cours.'])->assertRedirect();
        $this->assertSame('Suspendu', $this->agent->fresh()->payout_statuses['GC-015-202608']);
        $this->post("{$this->base}/gains/GC-015-202608/valider")->assertStatus(422);
        $this->post("{$this->base}/gains/GC-015-202608/reprendre")->assertRedirect();
        $this->post("{$this->base}/gains/GC-999-202608/valider")->assertNotFound();

        $this->get("{$this->base}/gains/releve")->assertOk()->assertDownload('releve-mariam-kone.csv');
        $this->get("{$this->base}/gains/GC-015-202608/justificatif")->assertOk();
        $this->get("{$this->base}/rapport")->assertOk();
    }

    public function test_territory_and_unavailability(): void
    {
        $this->post("{$this->base}/zones", ['zone' => 'Cocody', 'kind' => 'secondary'])->assertSessionHasErrors('zone');
        $this->post("{$this->base}/zones", ['zone' => 'Songon', 'kind' => 'primary'])->assertRedirect();
        $agent = $this->agent->fresh();
        $this->assertSame('Songon', $agent->zone);
        $this->assertSame('Cocody', $agent->secondary_zones[0]);

        $this->post("{$this->base}/zones/transfert", ['zone' => 'Cocody', 'to' => 'Adama Coulibaly', 'comment' => 'Réorganisation.'])->assertRedirect();
        $this->assertNotContains('Cocody', $this->agent->fresh()->secondary_zones);
        $this->assertContains('Cocody', SalesAgent::where('slug', 'adama-coulibaly')->first()->secondary_zones);

        $this->delete("{$this->base}/listes/communes", ['value' => 'Cocody'])->assertRedirect();
        $this->assertNotContains('Cocody', $this->agent->fresh()->communes);

        $this->post("{$this->base}/indisponibilite", ['starts_on' => today()->format('Y-m-d'), 'ends_on' => today()->subDay()->format('Y-m-d'), 'type' => 'leave', 'reason' => 'Maladie'])->assertSessionHasErrors('ends_on');
        $this->post("{$this->base}/indisponibilite", ['starts_on' => today()->format('Y-m-d'), 'ends_on' => today()->addDays(3)->format('Y-m-d'), 'type' => 'leave', 'reason' => 'Maladie', 'replacement' => 'Adama Coulibaly', 'transfer' => 'Oui'])->assertRedirect();
        $agent = $this->agent->fresh();
        $this->assertSame(SalesAgentAvailability::Leave, $agent->availability);
        $period = $agent->periods()->latest('id')->first();
        $this->assertStringContainsString('Dossiers transférés à Adama Coulibaly', $period->comment);

        $this->delete("{$this->base}/periodes/{$period->id}")->assertRedirect();
        $this->assertSame(SalesAgentAvailability::Available, $this->agent->fresh()->availability);
    }

    public function test_field_activities_and_worker_submission(): void
    {
        $activity = $this->agent->fieldActivities()->where('status', 'À vérifier')->firstOrFail();

        $this->post("{$this->base}/activites/{$activity->id}/validation")->assertRedirect();
        $this->assertSame('Validée', $activity->fresh()->status);
        $this->post("{$this->base}/activites/{$activity->id}/validation")->assertStatus(422);
        $this->post("{$this->base}/activites/{$activity->id}/anomalie", ['reason' => 'Photo sans géolocalisation.'])->assertRedirect();
        $this->assertSame('Anomalie signalée', $activity->fresh()->status);
        $this->get("{$this->base}/activites/export")->assertOk()->assertDownload('activite-mariam-kone.csv');

        $other = SalesAgent::where('slug', 'adama-coulibaly')->first();
        $foreign = $other->fieldActivities()->first();
        $this->post("{$this->base}/activites/{$foreign->id}/validation")->assertNotFound();

        $worker = Worker::factory()->create(['recruiter' => 'Mariam Koné', 'status' => WorkerStatus::Incomplete]);
        $this->post("{$this->base}/ouvriers/{$worker->slug}/soumission")->assertRedirect();
        $this->assertSame(WorkerStatus::ToReview, $worker->fresh()->status);
        $this->post("{$this->base}/ouvriers/{$worker->slug}/soumission")->assertStatus(422);

        $stranger = Worker::factory()->create(['recruiter' => 'Adama Coulibaly', 'status' => WorkerStatus::Incomplete]);
        $this->post("{$this->base}/ouvriers/{$stranger->slug}/soumission")->assertNotFound();
    }
}
