<?php

namespace Tests\Feature\Admin;

use App\Enums\ClaimStatus;
use App\Enums\DisputeStatus;
use App\Enums\RefundOrigin;
use App\Enums\RefundStatus;
use App\Models\Admin;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\PaymentOperator;
use App\Models\Refund;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\SiteClosure;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * Litiges : ouverture, instruction, décision, recours et remboursement.
 */
class DisputeAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $support;

    private Admin $direction;

    private Admin $direction2;

    private Admin $finance;

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function setUpSite(): void
    {
        $this->confirmSite();
        $this->support = Admin::factory()->role('support')->create();
        $this->direction = Admin::factory()->role('direction')->create();
        $this->direction2 = Admin::factory()->role('direction')->create();
        $this->finance = Admin::factory()->role('finance')->create();
    }

    private function url(string $id, string $action = ''): string
    {
        return "/api/v1/admin/disputes/{$id}".($action ? "/{$action}" : '');
    }

    private function mission(): Mission
    {
        return $this->assignmentOf('Kouamé Koffi')->mission;
    }

    /**
     * Réclamation du promoteur sur Kouamé, passée en litige et prise en charge par le support.
     */
    private function dispute(): string
    {
        $claim = $this->actingAsPromoter($this->promoter)->postJson('/api/v1/promoter/claims', ['category' => 'prestation', 'subject' => 'Travail mal exécuté', 'description' => 'Le mur doit être repris entièrement.', 'site_id' => $this->site->id, 'assignment_id' => $this->assignmentOf('Kouamé Koffi')->id])->assertCreated()->json('data.id');

        $this->as($this->support)->postJson("/api/v1/admin/claims/{$claim}/escalate", ['urgency' => 'haute'])->assertOk()->assertJsonPath('data.status.value', 'escaladee');
        $id = Dispute::query()->sole()->id;
        $this->postJson($this->url($id, 'take'))->assertOk()->assertJsonPath('data.status.value', 'en_instruction');

        return $id;
    }

    private function decide(string $id, array $body = [], ?Admin $admin = null): TestResponse
    {
        return $this->as($admin ?? $this->direction)->postJson($this->url($id, 'decision'), ['decision' => 'Le mur doit être repris par l’ouvrier.', ...$body]);
    }

    /**
     * Chantier arrêté après une journée validée pour Kouamé et Touré.
     */
    private function closedSite(): void
    {
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'))->assertSuccessful();
        $this->onWorkDay(0, '17:00');
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$this->startDate->toDateString()}/validate")->assertOk();
        app(SiteClosure::class)->close($this->site->refresh(), 'Chantier arrêté.');
    }

    public function test_a_claim_becomes_a_dispute_that_blocks_regularisation_until_the_decision(): void
    {
        $this->setUpSite();
        $id = $this->dispute();
        $mission = $this->mission();

        $this->assertSame(ClaimStatus::Escaladee, Claim::query()->sole()->status);
        $this->getJson($this->url($id))
            ->assertOk()
            ->assertJsonPath('data.parties.0.role.value', 'demandeur')
            ->assertJsonPath('data.parties.1.name', 'Kouamé Koffi')
            ->assertJsonPath('data.mission.id', $mission->id)
            ->assertJsonPath('data.claim.reference', Claim::query()->sole()->reference);
        $this->getJson('/api/v1/admin/disputes')->assertOk()->assertJsonPath('meta.counts.statuses.en_instruction', 1);

        $this->closedSite();
        $this->as($this->finance)->getJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk()->assertJsonFragment(['Litige ouvert']);

        $this->decide($id)->assertOk()->assertJsonPath('data.status.value', 'resolu');
        $this->as($this->finance)->getJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk()->assertJsonMissing(['Litige ouvert']);
    }

    public function test_the_owner_never_decides_and_support_has_no_right_to(): void
    {
        $this->setUpSite();
        $id = $this->dispute();

        $this->decide($id, [], $this->support)->assertForbidden();

        $super = Admin::factory()->role('super_admin')->create();
        $this->as($super)->postJson($this->url($id, 'take'))->assertOk();
        $this->decide($id, [], $super)->assertUnprocessable()->assertJsonPath('errors.dispute.0', 'Le responsable du dossier ne peut pas rendre la décision.');

        $this->decide($id)->assertOk();
        $this->decide($id, [], $this->direction2)->assertUnprocessable();
    }

    public function test_a_refund_waits_for_the_regularisation_then_needs_its_own_validations(): void
    {
        $this->setUpSite();
        $id = $this->dispute();

        $this->decide($id, ['refund_amount' => 5000, 'financial_note' => 'Geste convenu.'])
            ->assertOk()
            ->assertJsonPath('data.financial.refund_pending', true)
            ->assertJsonPath('data.financial.refund', null)
            ->assertJsonPath('data.can.request_refund', false);
        $this->assertSame(0, Refund::query()->count());
        $this->postJson($this->url($id, 'refund'))->assertUnprocessable()->assertJsonValidationErrors('dispute');

        $this->closedSite();
        $mission = $this->mission();
        $this->as($this->finance)->postJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk();
        Dispute::query()->whereKey($id)->update(['appeal_deadline' => now()->addDay()]);

        $this->as($this->direction)->getJson($this->url($id))->assertOk()->assertJsonPath('data.can.request_refund', true);
        $this->postJson($this->url($id, 'refund'))
            ->assertOk()
            ->assertJsonPath('data.financial.refund_pending', false)
            ->assertJsonPath('data.financial.refund.status.value', 'demande');

        $refund = Refund::query()->where('origin', RefundOrigin::Litige)->sole();
        $this->assertSame($id, $refund->dispute_id);
        $this->assertSame(5000, $refund->amount);
        $this->postJson($this->url($id, 'refund'))->assertUnprocessable();
    }

    public function test_a_refund_above_what_was_paid_refuses_the_whole_decision(): void
    {
        $this->setUpSite();
        $this->closedSite();
        $this->as($this->finance)->postJson("/api/v1/admin/regularisations/{$this->mission()->id}")->assertOk();
        $id = $this->dispute();

        $this->decide($id, ['refund_amount' => 999999999])->assertUnprocessable()->assertJsonValidationErrors('refund_amount');
        $this->assertSame(DisputeStatus::EnInstruction, Dispute::query()->findOrFail($id)->status);
        $this->decide($id, ['refund_amount' => 1000])->assertOk()->assertJsonPath('data.financial.refund.status.value', 'demande');
    }

    public function test_a_refund_needs_a_mission(): void
    {
        $this->setUpSite();
        $user = $this->promoter;
        $id = $this->as($this->support)->postJson('/api/v1/admin/disputes', ['claimant_type' => 'promoter_profile', 'dial_code' => $user->dial_code, 'phone_number' => $user->phone_number, 'category' => 'autre', 'urgency' => 'normale', 'description' => 'Litige sans mission identifiée.'])
            ->assertCreated()
            ->assertJsonPath('data.mission', null)
            ->json('data.id');
        $this->postJson($this->url($id, 'take'))->assertOk();

        $this->decide($id, ['refund_amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('dispute');

        $this->as($this->support)->postJson($this->url($id, 'mission'), ['mission_reference' => 'INCONNUE'])->assertUnprocessable()->assertJsonValidationErrors('mission_reference');
        $this->postJson($this->url($id, 'mission'), ['mission_reference' => $this->mission()->reference])->assertOk()->assertJsonPath('data.mission.id', $this->mission()->id);
    }

    public function test_a_party_appeals_and_a_different_person_rules_while_the_refund_waits(): void
    {
        $this->setUpSite();
        $this->closedSite();
        $this->as($this->finance)->postJson("/api/v1/admin/regularisations/{$this->mission()->id}")->assertOk();
        $id = $this->dispute();
        $this->decide($id, ['refund_amount' => 1000])->assertOk();

        $this->actingAsPromoter($this->promoter)->postJson("/api/v1/promoter/disputes/{$id}/appeal", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/promoter/disputes/{$id}/appeal", ['reason' => 'La décision ne tient pas compte des photos.'])->assertOk()->assertJsonPath('data.status.value', 'en_recours')->assertJsonPath('data.can_appeal', false);

        $refund = Refund::query()->where('origin', RefundOrigin::Litige)->sole();
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();
        $this->as(Admin::factory()->role('finance')->create())->postJson("/api/v1/admin/refunds/{$refund->id}/number", ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345678'])->assertOk();
        $this->as($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertUnprocessable()->assertJsonPath('errors.refund.0', 'Un recours est en cours sur le litige à l’origine de ce remboursement.');
        $this->assertSame(RefundStatus::Demande, $refund->fresh()->status);

        $appeal = Dispute::query()->whereNotNull('appeal_of_dispute_id')->sole();
        $this->assertSame(2, $appeal->parties()->count());
        $this->as($this->support)->postJson($this->url($appeal->id, 'take'))->assertOk()->assertJsonPath('data.appeal_of.id', $id)->assertJsonPath('data.can.decide_appeal', true);

        $body = ['outcome' => 'confirmee', 'decision' => 'Les photos ne changent pas l’appréciation.'];
        $this->as($this->support)->postJson($this->url($appeal->id, 'appeal-decision'), $body)->assertForbidden();
        $this->as($this->direction)->postJson($this->url($appeal->id, 'appeal-decision'), $body)->assertUnprocessable()->assertJsonPath('errors.dispute.0', 'Le recours doit être examiné par une autre personne que celle qui a décidé.');
        $this->as($this->direction2)->postJson($this->url($appeal->id, 'appeal-decision'), $body)->assertOk()->assertJsonPath('data.status.value', 'clos')->assertJsonPath('data.outcome', 'confirmee');

        $this->assertSame(DisputeStatus::Clos, Dispute::query()->findOrFail($id)->status);
    }

    public function test_an_appeal_is_refused_after_the_deadline_and_the_case_can_then_be_closed(): void
    {
        $this->setUpSite();
        $id = $this->dispute();
        $this->decide($id)->assertOk()->assertJsonPath('data.can.appeal', true)->assertJsonPath('data.can.close', false);
        $this->as($this->support)->postJson($this->url($id, 'close'))->assertUnprocessable();

        $this->travel(16)->days();
        $this->as($this->support)->postJson($this->url($id, 'appeal'), ['reason' => 'Trop tard pour ce recours.'])->assertUnprocessable()->assertJsonValidationErrors('dispute');
        $this->postJson($this->url($id, 'close'))->assertOk()->assertJsonPath('data.status.value', 'clos');
        $this->postJson($this->url($id, 'messages'), ['body' => 'Encore un mot.', 'internal' => false])->assertUnprocessable();
    }

    public function test_the_promoter_follows_the_case_without_seeing_internal_notes(): void
    {
        $this->setUpSite();
        $id = $this->dispute();

        $this->postJson($this->url($id, 'messages'), ['body' => 'Note réservée à l’équipe.', 'internal' => true])->assertOk();
        $this->assertSame(0, Notification::query()->where('event_code', 'dispute.updated')->where('body', 'like', '%a écrit%')->count());
        $this->postJson($this->url($id, 'messages'), ['body' => 'Pouvez-vous envoyer une photo ?', 'internal' => false])->assertOk();
        $this->assertSame(1, Notification::query()->where('event_code', 'dispute.updated')->where('body', 'like', '%a écrit%')->count());
        $this->post($this->url($id, 'proofs'), ['file' => UploadedFile::fake()->image('constat-equipe.jpg'), 'label' => 'Constat de l’équipe'], ['Accept' => 'application/json'])->assertOk();

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/disputes')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->post("/api/v1/promoter/disputes/{$id}/proofs", ['file' => UploadedFile::fake()->image('mur.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/promoter/disputes/{$id}/messages", ['body' => 'Voici la photo du mur.'])->assertOk();

        $response = $this->getJson("/api/v1/promoter/disputes/{$id}")->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonCount(1, 'data.proofs')->assertJsonPath('data.proofs.0.label', 'mur.jpg')->assertJsonPath('data.messages.1.mine', true);
        $this->assertStringNotContainsString('Note réservée', $response->getContent());

        $this->as($this->support)->getJson($this->url($id))->assertOk()->assertJsonCount(3, 'data.messages')->assertJsonCount(2, 'data.proofs')->assertJsonPath('data.messages.0.internal', true);

        $other = User::factory()->promoter()->create();
        $other->promoterProfile()->firstOrCreate([]);
        $this->actingAsPromoter($other)->getJson("/api/v1/promoter/disputes/{$id}")->assertNotFound();
    }
}
