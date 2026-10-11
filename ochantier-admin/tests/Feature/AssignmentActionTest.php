<?php

namespace Tests\Feature;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\PromoterOfferProfileStatus;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\PromoterOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentActionTest extends TestCase
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

    public function test_workers_are_contacted_validated_then_the_promoter_is_notified_and_pays(): void
    {
        $need = Need::factory()->withLine(quantity: 2, found: 2)->create();
        $ids = $need->assignments()->pluck('id')->all();
        $base = "/affectations/besoins/{$need->slug}";

        $this->post("{$base}/validation", ['ids' => $ids])->assertStatus(422);
        $this->post("{$base}/contact", ['ids' => $ids, 'channel' => 'Pigeon'])->assertSessionHasErrors('channel');
        $this->post("{$base}/contact", ['ids' => $ids, 'channel' => 'SMS', 'note' => 'Disponibles'])->assertSessionHasNoErrors();
        $this->assertSame(2, $need->assignments()->where('status', NeedAssignmentStatus::Contacted)->whereNotNull('contacted_on')->count());
        $this->assertSame(Need::COVERED, $this->needStatus($need));

        $this->post("{$base}/acompte", ['method' => 'Mobile Money', 'reference' => 'MM-1'])->assertStatus(422);
        $this->post("{$base}/validation", ['ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(Need::DEPOSIT, $this->needStatus($need));
        $this->assertNotNull($need->fresh()->notified_on);
        $this->assertDatabaseHas('need_activities', ['need_id' => $need->id, 'action' => 'a notifié automatiquement le promoteur']);

        $this->post("{$base}/acompte", ['method' => 'Mobile Money', 'reference' => ''])->assertSessionHasErrors('reference');
        $this->post("{$base}/acompte", ['method' => 'Mobile Money', 'reference' => 'MM-58214'])->assertSessionHasNoErrors();
        $this->assertSame(Need::DONE, $this->needStatus($need));
        $this->assertSame('Mobile Money · MM-58214', $need->fresh()->payment_reference);
    }

    public function test_a_validation_can_be_cancelled_until_the_deposit_is_paid(): void
    {
        $need = Need::factory()->withLine(quantity: 2, found: 1, status: NeedAssignmentStatus::Validated)->create();
        $assignment = $need->assignments()->first();

        $this->post("/affectations/besoins/{$need->slug}/ouvriers/{$assignment->id}/annulation-validation")->assertSessionHasNoErrors();
        $this->assertSame(NeedAssignmentStatus::Contacted, $assignment->fresh()->status);
    }

    public function test_declining_a_worker_opens_replacement_suggestions(): void
    {
        $need = Need::factory()->withLine(quantity: 2, found: 1, status: NeedAssignmentStatus::Contacted)->create();
        $assignment = $need->assignments()->first();
        $url = "/affectations/besoins/{$need->slug}/ouvriers/{$assignment->id}/invalidation";

        $this->post($url, ['motif' => 'Injoignable', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, ['motif' => 'Injoignable', 'reason' => 'Trois appels sans réponse'])
            ->assertRedirect("/affectations/besoins/{$need->slug}?remplacement={$assignment->reference}");
        $this->assertSame(NeedAssignmentStatus::Refused, $assignment->fresh()->status);
        $this->assertSame('Injoignable — Trois appels sans réponse', $assignment->fresh()->reason);
        $this->assertSame(Need::SEARCHING, $this->needStatus($need));
    }

    public function test_replacement_request_requires_a_paid_deposit(): void
    {
        $need = Need::factory()->state(['deposit_paid' => true, 'promoter_validated' => true])->withLine(quantity: 1, found: 1, status: NeedAssignmentStatus::Validated)->create();
        $assignment = $need->assignments()->first();

        $this->post("/affectations/besoins/{$need->slug}/ouvriers/{$assignment->id}/demande-remplacement", ['motif' => 'Absence prolongée', 'reason' => 'Absent depuis 5 jours'])->assertSessionHasNoErrors();
        $this->assertSame(NeedAssignmentStatus::Refused, $assignment->fresh()->status);
        $this->assertSame(Need::REPLACING, $this->needStatus($need));
    }

    public function test_closed_needs_are_read_only(): void
    {
        $need = Need::factory()->manual(NeedManualStatus::Cancelled)->withLine(quantity: 1, found: 1)->create();

        $this->post("/affectations/besoins/{$need->slug}/contact", ['ids' => $need->assignments()->pluck('id')->all(), 'channel' => 'SMS'])->assertStatus(422);
        $this->get("/affectations/besoins/{$need->slug}")->assertOk();
    }

    public function test_assignments_of_another_need_are_not_reachable(): void
    {
        $need = Need::factory()->withLine(found: 1)->create();
        $other = Need::factory()->withLine(found: 1, status: NeedAssignmentStatus::Contacted)->create();
        $foreign = $other->assignments()->first();

        $this->post("/affectations/besoins/{$need->slug}/ouvriers/{$foreign->id}/invalidation", ['motif' => 'Autre', 'reason' => 'Test'])->assertNotFound();
    }

    public function test_a_reinforcement_need_is_created_on_a_running_site(): void
    {
        $need = Need::factory()->state(['deposit_paid' => true, 'promoter_validated' => true])->withLine(quantity: 1, found: 1, status: NeedAssignmentStatus::Validated)->create();
        $data = ['trade' => 'Peintre', 'quantity' => 2, 'experience' => 'Intermédiaire', 'urgency' => 'Élevé', 'starts_on' => '2026-09-30', 'duration' => 14, 'daily_cost' => 9500, 'reason' => 'Finitions supplémentaires'];

        $this->post("/affectations/besoins/{$need->slug}/complement", [...$data, 'starts_on' => '2026-09-01'])->assertSessionHasErrors('starts_on');
        $this->post("/affectations/besoins/{$need->slug}/complement", $data)->assertSessionHasNoErrors();

        $child = Need::query()->where('parent_reference', $need->reference)->firstOrFail();
        $this->assertSame('Renfort d’équipe', $child->type);
        $this->assertSame(2, $child->lines()->first()->quantity);
        $this->assertSame(1, $child->offers()->count());
        $this->assertSame(Need::SEARCHING, $this->needStatus($child));
    }

    public function test_promoter_offer_is_processed_until_the_deposit_is_paid(): void
    {
        $offer = PromoterOffer::factory()->withProfiles(PromoterOfferProfileStatus::Pending, PromoterOfferProfileStatus::Contacted, PromoterOfferProfileStatus::Accepted)->create();
        [$pending, $contacted] = $offer->profiles()->get()->all();
        $base = "/affectations/offres/{$offer->slug}";

        $this->post("{$base}/contact", ['ids' => [$pending->id], 'channel' => 'WhatsApp'])->assertSessionHasNoErrors();
        $this->assertSame(PromoterOfferProfileStatus::Contacted, $pending->fresh()->status);

        $this->post("{$base}/validation", ['ids' => [$pending->id, $contacted->id]])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('AFF-2026-', $pending->fresh()->assignment_reference);
        $this->assertNotSame($pending->fresh()->assignment_reference, $contacted->fresh()->assignment_reference);
        $offer = $offer->fresh()->load('profiles');
        $this->assertSame(PromoterOffer::DEPOSIT, $offer->status());
        $this->assertNotNull($offer->notified_on);

        $this->post("{$base}/acompte", ['method' => 'Chèque', 'reference' => 'CHQ-77'])->assertSessionHasNoErrors();
        $this->assertSame(PromoterOffer::DONE, $offer->fresh()->load('profiles')->status());
    }

    public function test_promoter_offer_refusal_and_replacement(): void
    {
        $offer = PromoterOffer::factory()->withProfiles(PromoterOfferProfileStatus::Contacted, PromoterOfferProfileStatus::Accepted)->create();
        $profile = $offer->profiles()->first();
        $base = "/affectations/offres/{$offer->slug}";

        $this->post("{$base}/profils/{$profile->id}/refus", ['motif' => 'L’ouvrier refuse l’offre', 'reason' => 'Trop loin'])
            ->assertRedirect("{$base}?remplacement={$profile->id}");
        $this->assertSame(PromoterOfferProfileStatus::RefusedByWorker, $profile->fresh()->status);
        $this->assertSame(PromoterOffer::REPLACEMENT, $offer->fresh()->load('profiles')->status());

        $this->post("{$base}/profils/{$profile->id}/remplacant", ['key' => 'inconnu'])->assertStatus(422);
        $page = $this->get("{$base}?remplacement={$profile->id}")->assertOk();
        $key = $page->inertiaProps('replacement.rows.0.key');
        $this->post("{$base}/profils/{$profile->id}/remplacant", ['key' => $key])->assertSessionHasNoErrors();

        $this->assertSame(PromoterOfferProfileStatus::Replaced, $profile->fresh()->status);
        $this->assertDatabaseHas('promoter_offer_profiles', ['promoter_offer_id' => $offer->id, 'replaces' => $profile->name, 'status' => PromoterOfferProfileStatus::Pending->value, 'source' => 'Matching']);
        $this->assertSame(PromoterOffer::TO_PROCESS, $offer->fresh()->load('profiles')->status());
    }

    public function test_cancelled_promoter_offer_is_read_only(): void
    {
        $offer = PromoterOffer::factory()->withProfiles(PromoterOfferProfileStatus::Pending)->create();
        $base = "/affectations/offres/{$offer->slug}";

        $this->post("{$base}/annulation", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("{$base}/annulation", ['reason' => 'Le promoteur reporte le chantier'])->assertSessionHasNoErrors();
        $this->assertSame(PromoterOffer::CANCELLED, $offer->fresh()->load('profiles')->status());
        $this->post("{$base}/contact", ['ids' => $offer->profiles()->pluck('id')->all(), 'channel' => 'SMS'])->assertStatus(422);
    }

    public function test_assignment_references_follow_the_highest_number(): void
    {
        $need = Need::factory()->withLine(found: 1)->create();
        $need->assignments()->first()->update(['reference' => 'AFF-2026-1500']);

        $this->assertSame('AFF-2026-1501', NeedAssignment::nextReference());
    }
}
