<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Enums\QuoteVisitConfirmation;
use App\Enums\QuoteVisitStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QuoteBid;
use App\Models\QuoteRequestTrade;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;

/**
 * Visite d’un devis : déclarée faite par le professionnel, confirmée ou contestée par le promoteur (confirmée d’office
 * sans réponse), contestation tranchée par le Back Office. Les frais de visite ne sont dus qu’une fois confirmée.
 */
class QuoteVisitConfirmationTest extends ContractorMarketTestCase
{
    private string $site;

    private string $proposal;

    private string $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $this->site = $site['id'];
        $this->line = $this->line($site, 'electricien')->id;
        $this->proposal = $this->asContractor()->postJson("/api/v1/contractor/opportunities/quotes/{$this->line}/proposal", ['fee_amount' => 25000, 'visit_date' => now()->addDays(3)->toDateString(), 'visit_slot' => '10:00 – 12:00'])->json('data.id');
        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['quote_bid_ids' => [$this->proposal], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'devis-visite'])->assertCreated();
        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$this->proposal}/visit/done")->assertOk();
    }

    private function confirmation(): string
    {
        return "/api/v1/promoter/sites/{$this->site}/quote-request/visits/{$this->proposal}/confirmation";
    }

    private function promoterNotified(string $event): bool
    {
        return Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', $event)->exists();
    }

    private function contractorNotified(string $event): bool
    {
        return Notification::query()->where('recipient_id', $this->contractor->user_id)->where('event_code', $event)->exists();
    }

    /**
     * @return list<mixed>
     */
    private function payable(): array
    {
        $token = app(AdminSessions::class)->open(Admin::factory()->role('finance')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;

        return $this->withAccessToken($token)->getJson('/api/v1/admin/contractor-payouts')->assertOk()->json('data.payable');
    }

    public function test_the_promoter_is_notified_of_the_visit_and_confirms_it(): void
    {
        $this->assertTrue($this->promoterNotified('quote.visit_done'));
        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$this->site}/quote-request")
            ->assertJsonPath('data.trades.0.visit.confirmation.status.value', 'a_confirmer')
            ->assertJsonPath('data.trades.0.visit.confirmation.can_confirm', true);
        $this->assertSame([], $this->payable());

        $this->asPromoter()->postJson($this->confirmation(), ['confirmed' => true])->assertOk()->assertJsonPath('message', 'Visite confirmée');

        $this->assertSame(QuoteVisitConfirmation::Confirmee, QuoteBid::query()->find($this->proposal)->visit_confirmation);
        $this->assertTrue($this->contractorNotified('visit.confirmed'));
        $this->assertSame(25000, $this->payable()[0]['total']);
        $this->asPromoter()->postJson($this->confirmation(), ['confirmed' => true])->assertJsonValidationErrors(['visit' => 'Cette visite est déjà confirmée.']);
    }

    public function test_without_an_answer_the_visit_is_confirmed_after_three_days(): void
    {
        $this->travel(2)->days();
        $this->artisan('ochantier:confirm-quote-visits')->assertSuccessful();
        $this->assertNull(QuoteBid::query()->find($this->proposal)->visit_confirmation);

        $this->travel(1)->days();
        $this->artisan('ochantier:confirm-quote-visits')->expectsOutput('1 visite confirmée d’office.')->assertSuccessful();

        $this->assertSame(QuoteVisitConfirmation::Automatique, QuoteBid::query()->find($this->proposal)->visit_confirmation);
        $this->assertTrue($this->contractorNotified('visit.confirmed'));
        $this->assertSame(25000, $this->payable()[0]['total']);
    }

    public function test_a_contested_visit_blocks_the_fees_until_the_back_office_decides(): void
    {
        $this->asPromoter()->postJson($this->confirmation(), ['confirmed' => false])->assertJsonValidationErrors(['reason']);
        $this->postJson($this->confirmation(), ['confirmed' => false, 'reason' => 'Personne n’est venu au rendez-vous.'])->assertOk();

        $this->assertTrue($this->contractorNotified('visit.contested'));
        $this->assertSame([], $this->payable());
        $this->travel(5)->days();
        $this->artisan('ochantier:confirm-quote-visits')->expectsOutput('0 visite confirmée d’office.');
        $this->asContractor()->getJson("/api/v1/contractor/quote-proposals/{$this->proposal}")
            ->assertJsonPath('data.notice', 'Le promoteur indique que la visite n’a pas eu lieu. Vos frais de visite sont suspendus le temps que O’CHANTIER examine la situation.');

        $admin = $this->withAccessToken(app(AdminSessions::class)->open(Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
        $admin->getJson("/api/v1/admin/quote-requests/{$this->line}")
            ->assertJsonPath('data.status.value', 'visite_contestee')
            ->assertJsonPath('data.visit.confirmation.contest_reason', 'Personne n’est venu au rendez-vous.')
            ->assertJsonPath('data.actions.decide_visit', true);

        $this->postJson("/api/v1/admin/quote-requests/{$this->line}/visit-decision", ['decision' => 'annuler', 'reason' => 'Le professionnel reconnaît ne pas être venu.'])
            ->assertOk()
            ->assertJsonPath('data.visit.status.value', 'planifiee');

        $bid = QuoteBid::query()->find($this->proposal);
        $this->assertSame([QuoteVisitStatus::Planifiee, null], [$bid->visit_status, $bid->visit_confirmation]);
        $this->assertTrue($this->contractorNotified('visit.contest_decided'));
        $this->assertTrue($this->promoterNotified('quote.visit_decided'));
        $this->assertTrue(AuditLog::query()->where('action', 'quote_request.visit_cancelled')->exists());
        $this->assertSame([], $this->payable());
    }

    public function test_the_back_office_can_confirm_a_contested_visit(): void
    {
        $this->asPromoter()->postJson($this->confirmation(), ['confirmed' => false, 'reason' => 'Je n’étais pas présent, je ne sais pas.'])->assertOk();
        $operations = app(AdminSessions::class)->open(Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
        $decide = fn (string $reason) => $this->withAccessToken($operations)->postJson("/api/v1/admin/quote-requests/{$this->line}/visit-decision", ['decision' => 'confirmer', 'reason' => $reason]);

        $decide('Photos de la visite fournies par le professionnel.')->assertOk();

        $this->assertSame(QuoteVisitConfirmation::Confirmee, QuoteBid::query()->find($this->proposal)->visit_confirmation);
        $this->assertSame(25000, $this->payable()[0]['total']);
        $decide('Deuxième décision sur la même visite.')->assertJsonValidationErrors(['visit' => 'Cette visite n’est pas contestée.']);
        $this->assertSame(1, QuoteRequestTrade::query()->count());
    }
}
