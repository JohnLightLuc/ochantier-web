<?php

namespace Tests\Feature;

use App\Enums\AvailabilityType;
use App\Enums\WorkerAvailability;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerMissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkerSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function koffi(): Worker
    {
        return Worker::firstWhere('slug', 'koffi-adjoumani');
    }

    public function test_assignment_is_proposed_and_blocked_by_conflicting_periods(): void
    {
        $this->koffi()->periods()->create(['starts_on' => now()->addDays(10), 'ends_on' => now()->addDays(12), 'type' => AvailabilityType::Unavailable]);
        $payload = ['site' => 'Tour Horizon', 'offer' => 'Renfort maçon', 'type' => 'Ponctuelle', 'pay' => 'Journalier', 'amount' => '12000'];

        $this->post('/ouvriers/koffi-adjoumani/affectations', [...$payload, 'start' => now()->addDays(11)->format('Y-m-d'), 'end' => now()->addDays(13)->format('Y-m-d')])->assertSessionHasErrors('start');
        $this->post('/ouvriers/koffi-adjoumani/affectations', [...$payload, 'start' => now()->addDays(20)->format('Y-m-d'), 'end' => now()->addDays(22)->format('Y-m-d')])->assertRedirect();

        $koffi = $this->koffi();
        $this->assertSame('Proposée', $koffi->demo('assignments')[0]['status']);
        $this->assertSame(WorkerAvailability::PreAssigned, $koffi->availability);
    }

    public function test_assignment_transitions_follow_the_allowed_statuses(): void
    {
        $proposed = collect($this->koffi()->demo('assignments'))->firstWhere('status', 'Proposée')['id'];

        $this->post("/ouvriers/koffi-adjoumani/affectations/{$proposed}/suspendre")->assertStatus(422);
        $this->post("/ouvriers/koffi-adjoumani/affectations/{$proposed}/confirmer")->assertRedirect();
        $this->post("/ouvriers/koffi-adjoumani/affectations/{$proposed}/retirer", ['reason' => 'court'])->assertSessionHasErrors('reason');
        $this->post("/ouvriers/koffi-adjoumani/affectations/{$proposed}/retirer", ['reason' => 'Le chantier a été reporté.'])->assertRedirect();

        $this->assertSame('Annulée', collect($this->koffi()->demo('assignments'))->firstWhere('id', $proposed)['status']);
    }

    public function test_timesheets_can_be_flagged_corrected_approved_and_exported(): void
    {
        $this->post('/ouvriers/koffi-adjoumani/pointages/p0/correction', ['in' => '08:00', 'out' => '07:00', 'justification' => 'Attestation du chef.'])->assertSessionHasErrors('out');
        $this->post('/ouvriers/koffi-adjoumani/pointages/p0/correction', ['in' => '07:40', 'out' => '17:00', 'justification' => 'Attestation du chef de chantier.'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/pointages/p1/anomalie', ['type' => 'Retard', 'comment' => 'Arrivé à 9 h.'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/pointages/p1/validation')->assertRedirect();

        $sheets = collect($this->koffi()->demo('timesheets'))->keyBy('id');
        $this->assertSame('Corrigé', $sheets['p0']['status']);
        $this->assertSame('Validé', $sheets['p1']['status']);

        $this->assertStringContainsString('Date;Chantier', $this->get('/ouvriers/koffi-adjoumani/pointages/export')->streamedContent());
    }

    public function test_payouts_can_be_validated_retried_and_downloaded(): void
    {
        $mamadou = Worker::firstWhere('slug', 'mamadou-kone');
        $byStatus = collect($mamadou->demo('payouts'))->keyBy('status');

        $this->post("/ouvriers/mamadou-kone/paiements/{$byStatus['En attente de validation']['id']}/validation")->assertRedirect();
        $this->post("/ouvriers/mamadou-kone/paiements/{$byStatus['Échoué']['id']}/relance")->assertRedirect();
        $this->post("/ouvriers/mamadou-kone/paiements/{$byStatus['Payé']['id']}/relance")->assertStatus(422);

        $this->get("/ouvriers/mamadou-kone/paiements/{$byStatus['Payé']['id']}/releve")->assertOk()->assertSee('Commission 10 %');
    }

    public function test_reviews_can_be_moderated(): void
    {
        $this->post('/ouvriers/koffi-adjoumani/avis/av0/signalement', ['reason' => 'Propos déplacés envers l’équipe.'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/avis/av0/masquage')->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/avis/av0/restauration')->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/avis/av0/reponse', ['reply' => 'Merci pour votre retour.'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/avis/av0/litige', ['reason' => 'Avis contesté', 'priority' => 'Haute', 'description' => 'L’ouvrier conteste la note.'])->assertRedirect();

        $review = collect($this->koffi()->demo('reviews'))->firstWhere('id', 'av0');
        $this->assertSame('Publié', $review['status']);
        $this->assertSame('Merci pour votre retour.', $review['reply']);
        $this->assertSame('LIT-0111', $this->koffi()->activities()->first()->new_value);
    }
}
