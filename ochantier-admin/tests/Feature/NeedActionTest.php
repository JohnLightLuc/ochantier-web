<?php

namespace Tests\Feature;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedDocumentStatus;
use App\Enums\NeedManualStatus;
use App\Models\Need;
use App\Models\NeedOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NeedActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function needStatus(Need $need): string
    {
        return $need->fresh()->load('lines', 'offers.line', 'offers.assignments', 'assignments')->status();
    }

    public function test_suspension_and_reactivation(): void
    {
        $need = Need::factory()->withLine(found: 1)->create();
        $this->assertSame(Need::PARTIAL, $this->needStatus($need));

        $this->post("/besoins/{$need->slug}/suspension", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/besoins/{$need->slug}/suspension", ['reason' => 'Révision du planning'])->assertSessionHasNoErrors();
        $this->assertSame('Suspendu', $this->needStatus($need));
        $this->assertSame(NeedManualStatus::Suspended, $need->offers()->first()->manual_status);

        $this->post("/besoins/{$need->slug}/reactivation")->assertSessionHasNoErrors();
        $this->assertSame(Need::PARTIAL, $this->needStatus($need));
        $this->assertNull($need->offers()->first()->manual_status);
        $this->assertDatabaseHas('need_activities', ['need_id' => $need->id, 'action' => 'a réactivé le besoin']);
    }

    public function test_cancellation_closure_and_archiving(): void
    {
        $pending = Need::factory()->withLine()->create();
        $this->post("/besoins/{$pending->slug}/archivage")->assertStatus(422);
        $this->post("/besoins/{$pending->slug}/cloture")->assertStatus(422);
        $this->post("/besoins/{$pending->slug}/annulation", ['reason' => 'Report du lot'])->assertSessionHasNoErrors();
        $this->assertSame('Annulé', $this->needStatus($pending));
        $this->post("/besoins/{$pending->slug}/archivage")->assertSessionHasNoErrors();
        $this->assertSame('Archivé', $this->needStatus($pending));

        $done = Need::factory()->state(['deposit_paid' => true, 'promoter_validated' => true])->withLine(quantity: 2, found: 2, status: NeedAssignmentStatus::Validated)->create();
        $this->assertSame(Need::DONE, $this->needStatus($done));
        $this->post("/besoins/{$done->slug}/suspension", ['reason' => 'Impossible ici'])->assertStatus(422);
        $this->post("/besoins/{$done->slug}/cloture")->assertSessionHasNoErrors();
        $this->assertSame('Clôturé', $this->needStatus($done));
        $this->assertSame(NeedManualStatus::Closed, $done->offers()->first()->manual_status);
    }

    public function test_expired_offer_is_refreshed(): void
    {
        $need = Need::factory()->withLine(found: 1, deadline: '2026-09-20')->create();
        $offer = $need->offers()->first();
        $this->assertSame(Need::EXPIRED, $this->needStatus($need));

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-01'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-10-20'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-30'])->assertSessionHasNoErrors();

        $offer->refresh();
        $this->assertSame('2026-09-30', $offer->deadline->format('Y-m-d'));
        $this->assertSame(1, $offer->refreshes);
        $this->assertSame(Need::PARTIAL, $this->needStatus($need));
    }

    public function test_refreshing_an_offer_whose_start_has_passed_requires_a_new_start(): void
    {
        $need = Need::factory()->state(['starts_on' => '2026-09-22', 'ends_on' => '2026-10-20'])->withLine(deadline: '2026-09-18')->create();
        $offer = $need->offers()->first();

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-28'])->assertSessionHasErrors('starts_on');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-28', 'starts_on' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', $offer->fresh()->starts_on->format('Y-m-d'));
    }

    public function test_bulk_refresh_shifts_passed_starts(): void
    {
        $first = Need::factory()->state(['starts_on' => '2026-09-25'])->withLine(deadline: '2026-09-20')->create();
        $second = Need::factory()->withLine(deadline: '2026-09-21')->create();

        $this->post('/besoins/actualisation-groupee', ['deadline' => '2026-09-30', 'ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-02', $first->offers()->first()->starts_on->format('Y-m-d'));
        $this->assertSame('2026-10-13', $second->offers()->first()->starts_on->format('Y-m-d'));
        $this->assertSame(NeedOffer::PUBLISHED, $second->offers()->with('line', 'assignments')->first()->status());
    }

    public function test_open_offer_is_extended_and_applications_closed(): void
    {
        $need = Need::factory()->withLine()->create();
        $offer = $need->offers()->first();

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/prolongation", ['deadline' => '2026-10-08'])->assertSessionHasErrors('reason');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/prolongation", ['deadline' => '2026-10-01', 'reason' => 'Plus de temps'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/prolongation", ['deadline' => '2026-10-20', 'reason' => 'Plus de temps'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/prolongation", ['deadline' => '2026-10-08', 'reason' => 'Peu de candidatures'])->assertSessionHasNoErrors();
        $this->assertSame(1, $offer->fresh()->extensions);

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/prolongation", ['deadline' => '2026-10-01', 'mode' => 'modifier'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', $offer->fresh()->deadline->format('Y-m-d'));

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/cloture-candidatures")->assertSessionHasNoErrors();
        $this->assertFalse($offer->fresh()->applications_open);
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/cloture-candidatures")->assertStatus(422);
    }

    public function test_documents_are_added_validated_and_deleted_by_their_author(): void
    {
        Storage::fake();
        $need = Need::factory()->state(['anomalies' => ['Plan du chantier manquant']])->withLine()->create();
        $need->documents()->create(['type' => 'Plan du chantier', 'author' => 'N. Yao', 'status' => NeedDocumentStatus::Missing]);
        $other = $need->documents()->create(['type' => 'Planning', 'file_name' => 'planning.pdf', 'author' => 'SICOGI', 'status' => NeedDocumentStatus::Validated]);

        $this->post("/besoins/{$need->slug}/documents", ['type' => 'Plan du chantier', 'file' => UploadedFile::fake()->create('plan.pdf', 200, 'application/pdf')])->assertSessionHasNoErrors();
        $plan = $need->documents()->where('type', 'Plan du chantier')->sole();
        $this->assertSame(NeedDocumentStatus::ToReview, $plan->status);
        $this->assertSame([], $need->fresh()->anomalies);

        $this->post("/besoins/{$need->slug}/documents/{$plan->id}/validation")->assertSessionHasNoErrors();
        $this->assertSame(NeedDocumentStatus::Validated, $plan->fresh()->status);

        $this->delete("/besoins/{$need->slug}/documents/{$other->id}")->assertForbidden();
        $this->delete("/besoins/{$need->slug}/documents/{$plan->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($plan);
    }
}
