<?php

namespace Tests\Feature\Admin;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Offer;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Besoins : liste, compteurs, export, fiche, onglets, suspension, reprise, prolongation,
 * annulation et clôture.
 */
class OfferAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function offer(): Offer
    {
        return $this->site->offers()->firstOrFail();
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/admin/offers/'.$this->offer()->id.$suffix;
    }

    public function test_the_list_shows_one_line_per_offer_with_its_coverage(): void
    {
        $this->confirmSite();

        $this->asAdmin()->getJson('/api/v1/admin/offers')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', $this->offer()->reference)
            ->assertJsonPath('data.0.site.label', 'Résidence Les Palmiers')
            ->assertJsonPath('data.0.main_trade', 'Maçon')
            ->assertJsonPath('data.0.coverage.assigned', 3)
            ->assertJsonPath('data.0.coverage.percent', 100)
            ->assertJsonPath('meta.counts.statuses.'.$this->offer()->status->value, 1);

        foreach (['status=publiee', 'search=Palmiers', 'todo=a_matcher', 'todo=proposition', 'todo=acompte', 'todo=incomplet', 'todo=expires', 'sort=start', 'sort=deadline', 'sort=coverage&direction=desc', 'sort=status', 'sort=reference', 'urgency=normale', 'offer_type=ponctuelle'] as $query) {
            $this->getJson('/api/v1/admin/offers?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/offers?search=Palmiers')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/offers?status=inconnu')->assertJsonValidationErrors(['status']);

        $csv = $this->get('/api/v1/admin/offers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->offer()->reference, $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'offers.exported')->exists());
    }

    public function test_the_fiche_and_its_tabs(): void
    {
        $this->confirmSite();

        $this->asAdmin()->getJson($this->url())->assertOk()
            ->assertJsonPath('data.needs.0.trade', 'Maçon')
            ->assertJsonPath('data.can.suspend', false)
            ->assertJsonPath('data.can.cancel', false)
            ->assertJsonPath('data.can.close', true);

        $this->getJson($this->url('/workers'))->assertOk()->assertJsonPath('data.proposals.0.status.value', 'acceptee')->assertJsonCount(3, 'data.assigned');
        $this->getJson($this->url('/documents'))->assertOk();
        $this->getJson($this->url('/history'))->assertOk();
    }

    public function test_a_suspension_withdraws_the_pending_proposal_and_the_search_resumes(): void
    {
        $this->acceptProposal();
        $offer = $this->offer();

        // Une proposition acceptée attend un acompte : l’offre ne peut plus être arrêtée.
        $this->asAdmin()->postJson($this->url('/suspension'), ['reason' => 'Vérification du besoin.'])->assertJsonValidationErrors(['offer']);
        $this->postJson($this->url('/cancellation'), ['reason' => 'Vérification du besoin.'])->assertJsonValidationErrors(['offer']);

        $offer->proposals()->update(['status' => ProposalStatus::AValider]);
        $offer->needs()->update(['status' => NeedStatus::PropositionPromoteur]);
        $promoter = $this->promoter;

        $this->postJson($this->url('/suspension'), ['reason' => 'Vérification du besoin.'])->assertOk()
            ->assertJsonPath('data.status.value', 'suspendue')
            ->assertJsonPath('data.suspension_reason', 'Vérification du besoin.')
            ->assertJsonPath('data.can.resume', true);

        $this->assertSame(ProposalStatus::Annulee, $offer->proposals()->first()->status);
        $this->assertSame(NeedStatus::Matching, $offer->needs()->first()->status);
        $this->assertTrue(Notification::query()->where('event_code', 'offer.suspended')->where('recipient_id', $promoter->id)->exists());

        $this->actingAsPromoter($promoter);
        $this->getJson('/api/v1/promoter/reservations')->assertOk()->assertJsonFragment(['id' => $offer->id]);

        $this->asAdmin()->deleteJson($this->url('/suspension'))->assertOk()->assertJsonPath('data.status.value', $offer->statusHistories()->where('to_status', 'suspendue')->first()->from_status->value);
        $this->deleteJson($this->url('/suspension'))->assertJsonValidationErrors(['offer']);
    }

    public function test_a_deadline_can_be_extended(): void
    {
        $this->acceptProposal();
        $this->offer()->proposals()->update(['status' => ProposalStatus::Refusee]);

        $this->asAdmin()->postJson($this->url('/extension'), ['deadline' => now()->subDay()->toDateString()])->assertJsonValidationErrors(['deadline']);
        $this->postJson($this->url('/extension'), ['deadline' => now()->addWeek()->toDateString()])->assertOk()
            ->assertJsonPath('data.extension_count', 1);
        $this->assertSame(now()->addWeek()->toDateString(), $this->offer()->application_deadline->toDateString());
    }

    public function test_an_offer_without_payment_can_be_cancelled_with_its_needs(): void
    {
        $this->acceptProposal();
        $offer = $this->offer();
        $offer->proposals()->update(['status' => ProposalStatus::AValider]);
        $offer->needs()->update(['status' => NeedStatus::PropositionPromoteur]);

        $this->asAdmin()->postJson($this->url('/cancellation'), ['reason' => 'Doublon de réservation.'])->assertOk()
            ->assertJsonPath('data.status.value', 'annulee')
            ->assertJsonPath('data.cancellation_reason', 'Doublon de réservation.');

        $this->assertSame(NeedStatus::Annule, $offer->needs()->first()->status);
        $this->assertSame(ProposalStatus::Annulee, $offer->proposals()->first()->status);
        $this->postJson($this->url('/cancellation'), ['reason' => 'Une seconde fois.'])->assertJsonValidationErrors(['offer']);
    }

    public function test_cancelling_a_site_cancels_its_needs(): void
    {
        $this->acceptProposal();
        $offer = $this->offer();
        $this->site->refresh()->update(['status' => 'recherche']);
        $offer->proposals()->update(['status' => ProposalStatus::AValider]);
        $offer->needs()->update(['status' => NeedStatus::PropositionPromoteur]);

        $this->asAdmin()->postJson("/api/v1/admin/sites/{$this->site->id}/cancellation", ['reason' => 'Demande du promoteur.'])->assertOk();

        $this->assertSame(NeedStatus::Annule, $offer->needs()->first()->status);
    }

    public function test_a_filled_offer_can_be_closed_and_a_closed_site_ends_its_needs(): void
    {
        $this->confirmSite();
        $offer = $this->offer();

        $this->asAdmin()->postJson($this->url('/closure'), ['reason' => 'Équipe complète.'])->assertOk()->assertJsonPath('data.status.value', 'cloturee');
        $this->assertSame(OfferStatus::Cloturee, $offer->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'offer.closed')->exists());
        $this->postJson($this->url('/closure'), ['reason' => 'Une seconde fois.'])->assertJsonValidationErrors(['offer']);

        $this->postJson("/api/v1/admin/sites/{$this->site->id}/closure")->assertOk();
        $this->assertSame(NeedStatus::Termine, $offer->needs()->first()->status);

        $this->getJson($this->url('/history'))->assertJsonPath('data.0.what', 'a clôturé le besoin');
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $this->confirmSite();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/offers')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create());
        $this->postJson($this->url('/suspension'), ['reason' => 'Non autorisé.'])->assertForbidden();
        $this->postJson($this->url('/closure'), ['reason' => 'Non autorisé.'])->assertForbidden();
    }
}
