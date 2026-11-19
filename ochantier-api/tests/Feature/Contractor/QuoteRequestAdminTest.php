<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Enums\QuoteRequestStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Testing\TestResponse;

/**
 * Demandes de devis dans le Back Office : liste par corps de métier, statut affiché, fiche, prolongation,
 * annulation avant commande et relances.
 */
class QuoteRequestAdminTest extends ContractorMarketTestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->adminToken = $this->adminTokenFor(Admin::factory()->role('operations')->create());
    }

    public function test_the_list_shows_one_line_per_trade_with_its_displayed_status(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien', 'plombier']);
        $line = $this->line($site, 'electricien');
        $this->propose($line->id);

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.ouverte', 2)
            ->assertJsonPath('meta.counts.annulee', 0)
            ->assertJsonPath('meta.cities.0.name', 'Abidjan');

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests?trade=electricien&search=riviera')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $line->id)
            ->assertJsonPath('data.0.trade.code', 'electricien')
            ->assertJsonPath('data.0.site.commune', 'Cocody')
            ->assertJsonPath('data.0.promoter.name', 'Awa Kouamé')
            ->assertJsonPath('data.0.status', ['value' => 'ouverte', 'label' => 'Ouverte'])
            ->assertJsonPath('data.0.bids_count', 1)
            ->assertJsonPath('data.0.selected', null);

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests/'.$line->id)
            ->assertOk()
            ->assertJsonPath('data.request.trades_count', 2)
            ->assertJsonPath('data.request.other_lines.0.trade', 'Plombier')
            ->assertJsonPath('data.bids.0.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.bids.0.service_fee_amount', 2500)
            ->assertJsonPath('data.bids.0.amount', 27500)
            ->assertJsonPath('data.bids.0.is_shown', true)
            ->assertJsonPath('data.visit', null)
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.actions', ['extend' => true, 'cancel' => true, 'remind_promoter' => true, 'remind_contractor' => false])
            ->assertJsonPath('data.timeline.0.what', 'a proposé une visite')
            ->assertJsonPath('data.timeline.1.what', 'a publié la demande de devis');

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests?status=visite_programmee,devis_recu')->assertJsonPath('meta.total', 0);
        $this->asAdmin()->getJson('/api/v1/admin/quote-requests?status=inconnu')->assertUnprocessable();
    }

    public function test_an_expired_request_can_be_extended_then_cancelled_before_the_order(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $line = $this->line($site, 'electricien');
        $this->propose($line->id);
        $this->travel(4)->days();
        $this->adminToken = $this->adminTokenFor(Admin::factory()->role('operations')->create());

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests/'.$line->id)->assertJsonPath('data.status.value', 'expiree');

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/extension', ['deadline' => now()->subDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.deadline.0', 'La date limite doit être dans le futur.');

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/extension', ['deadline' => now()->addDays(3)->toDateString()])
            ->assertOk()
            ->assertJsonPath('message', 'Date limite prolongée.')
            ->assertJsonPath('data.status.value', 'ouverte')
            ->assertJsonPath('data.timeline.0.what', 'a prolongé la date limite');

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/cancellation', ['reason' => 'Doublon de la demande DV-2026-0002.'])
            ->assertOk()
            ->assertJsonPath('message', 'Demande de devis annulée.')
            ->assertJsonPath('data.status.value', 'annulee')
            ->assertJsonPath('data.bids.0.status.value', 'non_retenue')
            ->assertJsonPath('data.actions.cancel', false)
            ->assertJsonPath('data.timeline.0.what', 'a annulé la demande')
            ->assertJsonPath('data.timeline.0.detail', 'Doublon de la demande DV-2026-0002.');

        $this->assertSame('brouillon', QuoteRequest::query()->sole()->site->status->value);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'quote.cancelled')->exists());
        $this->assertStringContainsString('annulée par O’CHANTIER', Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'proposal.not_retained')->sole()->body);
        $this->assertSame(1, AuditLog::query()->where('action', 'quote_request.cancelled')->count());
    }

    public function test_an_ordered_request_cannot_be_cancelled_and_the_contractor_can_be_reminded_once_a_day(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $line = $this->line($site, 'electricien');
        $proposal = $this->propose($line->id)->json('data.id');
        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['quote_bid_ids' => [$proposal], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'devis-admin'])->assertCreated();
        $this->assertSame(QuoteRequestStatus::Commande, QuoteRequest::query()->sole()->status);

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests/'.$line->id)
            ->assertJsonPath('data.status.value', 'visite_programmee')
            ->assertJsonPath('data.selected.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.visit.status.value', 'planifiee')
            ->assertJsonPath('data.payment.amount', 27500)
            ->assertJsonPath('data.payment.status.value', 'reussie')
            ->assertJsonPath('data.actions', ['extend' => false, 'cancel' => false, 'remind_promoter' => false, 'remind_contractor' => true]);

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/cancellation', ['reason' => 'Demande du promoteur.'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quote_request.0', 'Une demande déjà commandée (visite payée) ne peut plus être annulée.');
        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/reminders', ['target' => 'promoter'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'Aucune action n’est attendue du promoteur à ce stade.');

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/reminders', ['target' => 'contractor'])
            ->assertOk()
            ->assertJsonPath('message', 'Relance envoyée au professionnel.')
            ->assertJsonPath('data.timeline.0.what', 'a envoyé une relance');
        $this->assertStringStartsWith('Rappel : la visite du chantier « Villa Riviera » est prévue le', Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'quote.reminder')->sole()->body);

        $this->asAdmin()->postJson('/api/v1/admin/quote-requests/'.$line->id.'/reminders', ['target' => 'contractor'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'Une relance a déjà été envoyée aujourd’hui à ce destinataire.');

        $this->asAdmin()->getJson('/api/v1/admin/quote-requests?status=visite_programmee')->assertJsonPath('meta.total', 1);
    }

    public function test_only_authorised_admins_decide(): void
    {
        $line = $this->line($this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']), 'electricien');
        $support = $this->adminTokenFor(Admin::factory()->role('support')->create());

        $this->withAccessToken($support)->postJson('/api/v1/admin/quote-requests/'.$line->id.'/cancellation', ['reason' => 'Demande du promoteur.'])->assertForbidden();
        $this->asAdmin()->getJson('/api/v1/admin/quote-requests/01a1f369-0000-7000-8000-000000000000')->assertNotFound();
    }

    private function propose(string $lineId): TestResponse
    {
        return $this->asContractor()->postJson("/api/v1/contractor/opportunities/quotes/{$lineId}/proposal", [
            'fee_amount' => 25000,
            'visit_date' => now()->addDays(3)->toDateString(),
            'visit_slot' => '10:00 – 12:00',
        ])->assertCreated();
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function asAdmin(): static
    {
        return $this->withAccessToken($this->adminToken);
    }
}
