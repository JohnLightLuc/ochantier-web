<?php

namespace Tests\Feature\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\StaffingRequestStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\PaymentTransaction;
use App\Models\StaffingRequest;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Testing\TestResponse;

class ContractorStaffingTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->subscribe();
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->day(10), 'end_date' => $this->day(39), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-effectifs'])->assertCreated();
        $this->asContractor();
    }

    public function test_a_partial_offer_is_paid_and_the_search_continues_for_the_missing_workers(): void
    {
        $this->worker('Yao Koffi', 'macon', 20000);
        $this->worker('Bamba Seydou', 'macon', 22000);
        $this->worker('Awa Traoré', 'plombier', 18000);
        $this->worker('Ali Ouattara', 'macon', 15000, AvailabilityStatus::Indisponible);

        $this->publish(['start_date' => $this->day(5), 'days' => 14, 'lines' => [['trade_id' => $this->trade('macon'), 'headcount' => 3, 'daily_rate' => 20000]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_date.0', 'La période doit être comprise dans les dates du chantier ('.now()->addDays(10)->format('d/m/Y').' – '.now()->addDays(39)->format('d/m/Y').').');

        $id = $this->publish()
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'recherche')
            ->assertJsonPath('data.summary', '3 × Maçon, 1 × Plombier')
            ->assertJsonPath('data.wanted', 4)
            ->assertJsonPath('data.conditions.estimate', true)
            ->assertJsonPath('data.conditions.total', (3 * 20000 + 18000) * 14)
            ->assertJsonPath('data.actions.cancel', true)
            ->json('data.id');

        $this->artisan('ochantier:simulate-staffing')->assertSuccessful();
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'staffing.offer')->exists());

        $total = (2 * 20000 + 18000) * 14;
        $detail = $this->getJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'offre')
            ->assertJsonPath('data.found', 3)
            ->assertJsonPath('data.banner.title', 'Proposition partielle')
            ->assertJsonPath('data.lines.0.missing', 1)
            ->assertJsonPath('data.conditions.total', $total)
            ->assertJsonPath('data.conditions.service_fee_amount', (int) round($total * 5 / 100))
            ->assertJsonPath('data.tracking.2.state', 'current')
            ->json('data');
        $this->assertNotContains('Ali Ouattara', array_column($detail['lines'][0]['workers'], 'name'));

        $quote = $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$id}/payment-quote", ['operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.amount', $detail['conditions']['deposit_amount'] + $detail['conditions']['service_fee_amount'])
            ->json('data');
        $this->assertGreaterThan($quote['amount'], $quote['total']);

        $this->pay($id)
            ->assertCreated()
            ->assertJsonPath('message', 'Acompte payé : les ouvriers ont rejoint votre équipe.')
            ->assertJsonPath('data.transaction.status.value', 'reussie')
            ->assertJsonPath('data.status.value', 'payee')
            ->assertJsonPath('data.payment.operator', 'Orange Money')
            ->assertJsonPath('data.installments.0.label', 'Semaine 1/2');
        $this->pay($id)->assertCreated();

        $transaction = PaymentTransaction::query()->where('purpose', PaymentTransactionPurpose::Effectifs->value)->sole();
        $this->assertSame('contractor', $transaction->payer_type);
        $this->assertSame(3, Assignment::query()->where('status', AssignmentStatus::Confirmee->value)->count());
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'staffing.paid')->exists());

        $installments = StaffingRequest::query()->findOrFail($id)->installments;
        $this->assertSame($total - $detail['conditions']['deposit_amount'], (int) $installments->sum('amount'));

        $this->getJson("/api/v1/contractor/sites/{$this->site}/team")->assertJsonPath('data.count', 3);
        $list = $this->getJson("/api/v1/contractor/sites/{$this->site}/staffing-requests")->assertJsonPath('meta.count', 2)->json('data');
        $continuation = collect($list)->firstWhere('parent_request_id', $id);
        $this->assertSame('1 × Maçon', $continuation['summary']);
        $this->assertSame('recherche', $continuation['status']['value']);

        $due = $this->getJson('/api/v1/contractor/payments/installments')->assertOk()->assertJsonPath('meta.count', 2)->json('data');
        $installmentQuote = $this->postJson("/api/v1/contractor/payments/installments/{$due[0]['id']}/quote", ['operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('data.amount', $due[0]['amount'])
            ->json('data');
        $this->assertSame($installmentQuote['amount'] + $installmentQuote['fees'], $installmentQuote['total']);
        $this->postJson("/api/v1/contractor/payments/installments/{$due[0]['id']}/pay", ['operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'], ['Idempotency-Key' => 'semaine-1'])
            ->assertCreated()
            ->assertJsonPath('message', 'Échéance payée.');
        $this->postJson("/api/v1/contractor/payments/installments/{$due[0]['id']}/pay", ['operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'], ['Idempotency-Key' => 'semaine-1-bis'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.installment.0', 'Cette échéance est déjà payée.');
        $this->getJson('/api/v1/contractor/payments/installments')->assertJsonPath('meta.count', 1);

        $this->asPromoter()->getJson('/api/v1/promoter/payments')->assertJsonMissing(['label' => 'Semaine 1/2']);
    }

    public function test_an_offer_can_be_refused_and_a_search_cancelled(): void
    {
        $this->worker('Yao Koffi', 'macon', 20000);
        $id = $this->publish(['start_date' => $this->day(10), 'days' => 7, 'lines' => [['trade_id' => $this->trade('macon'), 'headcount' => 1, 'daily_rate' => 20000]]])->json('data.id');

        $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$id}/refuse")
            ->assertUnprocessable()
            ->assertJsonPath('errors.staffing_request.0', 'Aucune offre n’est en attente pour cette recherche.');

        $this->artisan('ochantier:simulate-staffing', ['request' => $id])->assertSuccessful();
        $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$id}/refuse")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'refusee')
            ->assertJsonPath('data.lines.0.workers.0.status', 'libere');
        $this->assertSame(0, Assignment::query()->count());

        $other = $this->publish(['start_date' => $this->day(12), 'days' => 3, 'lines' => [['trade_id' => $this->trade('plombier'), 'headcount' => 1, 'daily_rate' => 18000]]])->json('data.id');
        $this->artisan('ochantier:simulate-staffing', ['request' => $other])->assertSuccessful();
        $this->assertSame(StaffingRequestStatus::Recherche, StaffingRequest::query()->findOrFail($other)->status);
        $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$other}/cancel")->assertOk()->assertJsonPath('data.status.value', 'annulee');
    }

    public function test_the_back_office_builds_the_offer_with_chosen_workers(): void
    {
        $first = $this->worker('Yao Koffi', 'macon', 20000);
        $chosen = $this->worker('Bamba Seydou', 'macon', 22000);
        $id = $this->publish(['start_date' => $this->day(10), 'days' => 7, 'lines' => [['trade_id' => $this->trade('macon'), 'headcount' => 1, 'daily_rate' => 20000]]])->json('data.id');
        $line = StaffingRequest::query()->findOrFail($id)->lines()->sole();

        $admin = Admin::factory()->role('operations')->create();
        $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);

        $this->getJson('/api/v1/admin/staffing-requests')->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.contractor.name', 'Yao Serge Koffi');
        $this->postJson("/api/v1/admin/staffing-requests/{$id}/offer", ['workers' => [['line_id' => $line->id, 'worker_id' => $chosen->id]]])
            ->assertOk()
            ->assertJsonPath('message', 'Offre envoyée à l’entrepreneur.')
            ->assertJsonPath('data.lines.0.workers.0.name', 'Bamba Seydou')
            ->assertJsonPath('data.banner.title', 'Proposition complète');
        $this->assertNotSame($first->id, $line->workers()->sole()->worker_id);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function publish(?array $data = null): TestResponse
    {
        return $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests", $data ?? [
            'start_date' => $this->day(10),
            'days' => 14,
            'lines' => [
                ['trade_id' => $this->trade('macon'), 'headcount' => 3, 'daily_rate' => 20000],
                ['trade_id' => $this->trade('plombier'), 'headcount' => 1, 'daily_rate' => 18000],
            ],
        ]);
    }

    private function pay(string $id): TestResponse
    {
        return $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests/{$id}/pay", ['operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'], ['Idempotency-Key' => 'effectifs-'.$id]);
    }

    private function day(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }
}
