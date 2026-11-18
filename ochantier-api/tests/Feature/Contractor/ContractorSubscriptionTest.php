<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\SubscriptionStatus;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Worker;
use App\Support\Contractor\ContractorSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

class ContractorSubscriptionTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->day(10), 'end_date' => $this->day(39), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-abonnement'])->assertCreated();
        $this->asContractor();
    }

    public function test_solo_locks_the_team_features(): void
    {
        $worker = $this->worker('Yao Koffi', 'macon', 20000);

        $this->getJson('/api/v1/contractor/subscription')
            ->assertOk()
            ->assertJsonPath('data.current.plan.code', 'entrepreneur_solo')
            ->assertJsonPath('data.current.status', ['value' => 'gratuit', 'label' => 'Gratuit'])
            ->assertJsonPath('data.plans.*.code', ['entrepreneur_solo', 'entrepreneur_essentiel', 'entrepreneur_pro'])
            ->assertJsonPath('data.plans.2.recommended', true);

        $this->assign([$worker])
            ->assertForbidden()
            ->assertJsonPath('message', 'Ajout d’ouvriers : fonction incluse dans l’abonnement Essentiel ou Pro. Vos données restent consultables.');
        $this->getJson("/api/v1/contractor/sites/{$this->site}/team")
            ->assertOk()
            ->assertJsonPath('data.subscription.subscribed', false)
            ->assertJsonPath('data.subscription.lock_message', 'L’ajout de membres d’équipe, le pointage et le rapport de fin de chantier sont activés par abonnement.');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/absences", ['assignment_id' => fake()->uuid()])->assertForbidden();
        $this->getJson("/api/v1/contractor/sites/{$this->site}/report")->assertJsonPath('data.available', false);
    }

    public function test_the_contractor_subscribes_by_mobile_money_within_the_seat_limit(): void
    {
        $this->postJson('/api/v1/contractor/subscription/quote', ['plan_code' => 'entrepreneur_solo', 'operator_code' => 'orange_money'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.plan_code.0', 'La formule Solo est gratuite : résiliez votre abonnement pour y revenir.');

        $quote = $this->postJson('/api/v1/contractor/subscription/quote', ['plan_code' => 'entrepreneur_essentiel', 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.amount', 15000)
            ->json('data');
        $this->assertGreaterThan(15000, $quote['total']);

        $this->pay('entrepreneur_essentiel', 'abonnement-1')
            ->assertCreated()
            ->assertJsonPath('message', 'Abonnement activé.')
            ->assertJsonPath('data.current.plan.code', 'entrepreneur_essentiel')
            ->assertJsonPath('data.current.status.value', 'actif')
            ->assertJsonPath('data.current.seats', 5)
            ->assertJsonPath('data.invoices.0.status.value', 'payee')
            ->assertJsonPath('data.actions.cancel', true);

        $transaction = PaymentTransaction::query()->where('purpose', PaymentTransactionPurpose::Abonnement->value)->sole();
        $this->assertSame('contractor', $transaction->payer_type);
        $this->assertNotNull($transaction->subscription_invoice_id);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'subscription.paid')->exists());

        $workers = collect(range(1, 6))->map(fn (int $index) => $this->worker('Ouvrier '.$index.' Test', 'macon', 20000))->all();
        $this->assign($workers)
            ->assertUnprocessable()
            ->assertJsonPath('errors.workers.0', 'Plafond de votre abonnement atteint (5 membres actifs par chantier). Passez à Pro pour en affecter jusqu’à 20.');
        $this->assign(array_slice($workers, 0, 5))->assertCreated()->assertJsonPath('data.subscription.available_seats', 0);

        $this->pay('entrepreneur_pro', 'abonnement-pro')->assertCreated()->assertJsonPath('data.current.seats', 20);
        $this->assertSame(SubscriptionStatus::Resiliee, Subscription::query()->whereHas('plan', fn ($query) => $query->where('code', 'entrepreneur_essentiel'))->sole()->status);
        $this->assign([$workers[5]])->assertCreated();

        $invoice = $this->getJson('/api/v1/contractor/subscription')->json('data.invoices.0.id');
        $pdf = $this->get("/api/v1/contractor/subscription/invoices/{$invoice}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_cancelled_subscription_ends_with_its_period_and_an_unpaid_renewal_expires(): void
    {
        $this->pay('entrepreneur_essentiel', 'abonnement-2')->assertCreated();

        $this->postJson('/api/v1/contractor/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.current.status', ['value' => 'resilie', 'label' => 'Résilié'])
            ->assertJsonPath('data.actions.reactivate', true);
        $this->postJson('/api/v1/contractor/subscription/reactivate')->assertOk()->assertJsonPath('data.current.status.value', 'actif');

        $renewal = CarbonImmutable::today()->addMonth()->addDay();
        $this->travelTo($renewal);
        $this->artisan('ochantier:renew-subscriptions')->assertSuccessful();

        $this->getJson('/api/v1/contractor/subscription')
            ->assertJsonPath('data.current.status.value', 'en_retard')
            ->assertJsonPath('data.open_invoice.status.value', 'en_retard');
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'subscription.renewal')->exists());

        $this->travelTo($renewal->addDays(ContractorSubscriptions::GRACE_DAYS + 1));
        app(ContractorSubscriptions::class)->renew();

        $this->getJson('/api/v1/contractor/subscription')
            ->assertJsonPath('data.current.status.value', 'expire')
            ->assertJsonPath('data.current.subscribed', false);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'subscription.expired')->exists());
    }

    /**
     * @param  list<Worker>  $workers
     */
    private function assign(array $workers): TestResponse
    {
        return $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $this->day(10),
            'end_date' => $this->day(20),
            'workers' => array_map(fn ($worker) => ['worker_id' => $worker->id, 'role' => 'Maçon', 'daily_rate' => 20000], $workers),
        ]);
    }

    private function pay(string $plan, string $key): TestResponse
    {
        return $this->postJson('/api/v1/contractor/subscription', ['plan_code' => $plan, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'], ['Idempotency-Key' => $key]);
    }

    private function day(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }
}
