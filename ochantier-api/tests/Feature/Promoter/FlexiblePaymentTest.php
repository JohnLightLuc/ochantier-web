<?php

namespace Tests\Feature\Promoter;

use App\Enums\PaymentInstallmentStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentInstallment;
use App\Models\PaymentSchedule;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class FlexiblePaymentTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Proposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proposal = $this->acceptProposal();
    }

    public function test_flexible_payment_is_reserved_to_subscribers(): void
    {
        $this->getJson('/api/v1/promoter/payments/options')
            ->assertJsonPath('data.flexible.available', false)
            ->assertJsonPath('data.flexible.min_amount', 20000);

        $this->postJson('/api/v1/promoter/payments/quote', ['proposal_id' => $this->proposal->id, 'installments' => 2, 'operator_code' => 'orange_money'])
            ->assertJsonValidationErrors(['installments' => 'Le paiement en plusieurs fois est réservé aux abonnés.']);
        $this->postJson('/api/v1/promoter/payments/quote', ['proposal_id' => $this->proposal->id, 'installments' => 4, 'operator_code' => 'orange_money'])
            ->assertJsonValidationErrors(['installments' => 'Payez en 1, 2 ou 3 fois.']);
    }

    public function test_a_deposit_is_paid_in_three_weekly_installments(): void
    {
        $this->subscribed();
        $deposit = $this->proposal->deposit_amount;
        $first = (int) (round($deposit / 3 / 100) * 100);
        $second = (int) (round(($deposit - $first) / 2 / 100) * 100);
        $next = now()->addDays(7)->locale('fr')->translatedFormat('j M Y');

        $this->getJson('/api/v1/promoter/payments/options')->assertJsonPath('data.flexible.available', true);
        $this->postJson('/api/v1/promoter/payments/quote', ['proposal_id' => $this->proposal->id, 'installments' => 3, 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.amount', $first)
            ->assertJsonPath('data.total_amount', $deposit)
            ->assertJsonPath('data.installments.*.amount', [$first, $second, $deposit - $first - $second])
            ->assertJsonPath('data.installments.*.due_date', [today()->toDateString(), today()->addDays(7)->toDateString(), today()->addDays(14)->toDateString()])
            ->assertJsonPath('data.lines.5', ['label' => 'Versement 1/3 aujourd’hui', 'amount' => $first])
            ->assertJsonPath('data.lines.6', ['label' => 'Reste en 2 versements · tous les 7 jours', 'amount' => $deposit - $first]);

        $this->pay(['proposal_id' => $this->proposal->id, 'installments' => 3])
            ->assertCreated()
            ->assertJsonPath('data.amount', $first)
            ->assertJsonPath('data.message', 'Équipe confirmée. Démarrage prévu le '.$this->startDate->locale('fr')->translatedFormat('j M Y').'. Prochain versement le '.$next.'.');

        $this->assertSame(SiteStatus::Confirme, $this->site->refresh()->status);
        $this->assertSame([PaymentInstallmentStatus::Payee, PaymentInstallmentStatus::APayer, PaymentInstallmentStatus::APayer], PaymentInstallment::query()->orderBy('sequence')->pluck('status')->all());
        $this->assertSame($first, (int) PaymentSchedule::query()->sum('amount_paid'));
        $this->assertContains(PaymentScheduleStatus::PartiellementPayee, PaymentSchedule::query()->pluck('status')->all());

        $rows = $this->getJson('/api/v1/promoter/payments')->assertOk()->json('data');
        $versements = collect($rows)->where('kind.value', 'versement')->keyBy('short');
        $this->assertSame(['Versement 2/3', 'Versement 3/3', 'Versement 1/3'], collect($rows)->where('kind.value', 'versement')->pluck('short')->values()->all());
        $this->assertSame([true, false], [$versements['Versement 2/3']['can_pay'], $versements['Versement 3/3']['can_pay']]);
        $this->assertSame('effectue', $versements['Versement 1/3']['status']['value']);
        $this->assertSame([], collect($rows)->where('kind.value', 'acompte')->all(), 'L’acompte réglé en plusieurs fois n’apparaît que par ses versements.');

        $this->pay(['installment_id' => $versements['Versement 3/3']['target']['installment_id']])
            ->assertJsonValidationErrors(['installment_id' => 'Réglez d’abord le versement 2/3.']);
        $this->pay(['installment_id' => $versements['Versement 2/3']['target']['installment_id']])
            ->assertCreated()
            ->assertJsonPath('data.amount', $second)
            ->assertJsonPath('data.message', 'Versement 2/3 réglé.');
        $this->pay(['installment_id' => $versements['Versement 2/3']['target']['installment_id']])
            ->assertJsonValidationErrors(['installment_id' => 'Ce versement est déjà réglé.']);
        $this->pay(['installment_id' => $versements['Versement 3/3']['target']['installment_id']])->assertCreated();

        $this->assertSame($deposit, (int) PaymentSchedule::query()->sum('amount_paid'));
        $deposits = PaymentSchedule::query()->where('amount_paid', '>', 0)->pluck('status')->unique()->values()->all();
        $this->assertSame([PaymentScheduleStatus::Payee], $deposits);

        $after = collect($this->getJson('/api/v1/promoter/payments')->json('data'));
        $this->assertSame([], $after->where('kind.value', 'versement')->all());
        $this->assertSame('effectue', $after->firstWhere('kind.value', 'acompte')['status']['value']);
    }

    public function test_installments_of_other_promoters_are_out_of_reach(): void
    {
        $this->subscribed();
        $this->pay(['proposal_id' => $this->proposal->id, 'installments' => 2])->assertCreated();
        $installment = PaymentInstallment::query()->where('sequence', 2)->sole();

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->pay(['installment_id' => $installment->id])->assertJsonValidationErrors(['installment_id' => 'Versement introuvable.']);
    }

    private function subscribed(): void
    {
        Subscription::query()->create([
            'reference' => 'ABO-TEST-0001',
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'plan_id' => SubscriptionPlan::query()->where('code', 'equipe')->value('id'),
            'status' => SubscriptionStatus::Active,
            'started_at' => today(),
            'current_period_start' => today(),
            'current_period_end' => today()->addMonth()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function pay(array $target): TestResponse
    {
        return $this->postJson('/api/v1/promoter/payments', [...$target, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634']);
    }
}
