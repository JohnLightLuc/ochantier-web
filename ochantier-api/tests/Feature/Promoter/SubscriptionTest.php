<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CommercialOfferSeeder;
use Illuminate\Testing\TestResponse;

class SubscriptionTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CommercialOfferSeeder::class);
    }

    public function test_a_promoter_starts_on_the_free_solo_plan(): void
    {
        $this->getJson('/api/v1/promoter/subscription')
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.status', ['value' => 'gratuit', 'label' => 'Gratuit'])
            ->assertJsonPath('data.plan.code', 'solo')
            ->assertJsonPath('data.subtitle', 'Compte à utilisateur unique · propriétaire')
            ->assertJsonPath('data.seats.label', '1 / 1 utilisateur')
            ->assertJsonPath('data.plans.*.code', ['solo', 'equipe', 'pro', 'entreprise'])
            ->assertJsonPath('data.plans.*.price', [0, 15000, 35000, 75000])
            ->assertJsonPath('data.plans.0.button_label', 'Formule actuelle')
            ->assertJsonPath('data.plans.0.can_choose', false)
            ->assertJsonPath('data.plans.1.button_label', "Souscrire à Équipe · 15\u{00A0}000\u{00A0}FCFA/mois")
            ->assertJsonPath('data.plans.3.seats_label', 'Jusqu’à 50 utilisateurs')
            ->assertJsonPath('data.invoices', [])
            ->assertJsonPath('data.cancellation.refusal', 'Vous êtes déjà sur la formule Solo.');
    }

    public function test_subscribing_pays_the_first_month_by_mobile_money(): void
    {
        $this->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'equipe', 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'abonnement')
            ->assertJsonPath('data.lines.*.label', ['Abonnement Équipe · 1 mois', 'Frais opérateur Orange Money 1,5 %'])
            ->assertJsonPath('data.lines.*.amount', [15000, 225])
            ->assertJsonPath('data.total', 15225);

        $this->subscribe('equipe')
            ->assertCreated()
            ->assertJsonPath('data.purpose.value', 'abonnement')
            ->assertJsonPath('data.status.value', 'reussie')
            ->assertJsonPath('data.site_id', null)
            ->assertJsonPath('data.message', 'Votre formule Équipe est active. Invitez jusqu’à 4 collaborateurs.');

        $renewal = CarbonImmutable::today()->addMonthNoOverflow()->locale('fr')->translatedFormat('j M Y');
        $this->getJson('/api/v1/promoter/subscription')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.status.value', 'actif')
            ->assertJsonPath('data.plan.code', 'equipe')
            ->assertJsonPath('data.subtitle', "15\u{00A0}000\u{00A0}FCFA / mois · renouvelé le ".$renewal)
            ->assertJsonPath('data.seats.label', '1 / 5 utilisateurs')
            ->assertJsonPath('data.seats.left_label', '4 places restantes')
            ->assertJsonPath('data.next_due_label', $renewal)
            ->assertJsonPath('data.method', 'Orange Money')
            ->assertJsonPath('data.plans.1.current', true)
            ->assertJsonPath('data.plans.1.button_label', 'Formule actuelle')
            ->assertJsonPath('data.plans.2.button_label', "Passer à Pro · 35\u{00A0}000\u{00A0}FCFA/mois")
            ->assertJsonPath('data.plans.0.button_label', 'Revenir à la formule Solo')
            ->assertJsonCount(1, 'data.invoices')
            ->assertJsonPath('data.invoices.0.amount', 15000)
            ->assertJsonPath('data.invoices.0.status.value', 'payee');

        $subscription = Subscription::query()->sole();
        $invoice = SubscriptionInvoice::query()->sole();
        $transaction = PaymentTransaction::query()->sole();
        $this->assertStringStartsWith('ABO-', $subscription->reference);
        $this->assertStringStartsWith('FAB-', $invoice->reference);
        $this->assertSame([PaymentTransactionPurpose::Abonnement, $invoice->id], [$transaction->purpose, $transaction->subscription_invoice_id]);
        $this->assertSame(['subscription_invoice', $invoice->id], [Receipt::query()->sole()->subject_type, Receipt::query()->sole()->subject_id]);
        $this->assertNotNull($this->getJson('/api/v1/promoter/subscription')->json('data.invoices.0.receipt_url'));

        $this->subscribe('equipe')->assertJsonValidationErrors(['plan_code' => 'C’est déjà votre formule actuelle.']);
        $this->subscribe('solo')->assertJsonValidationErrors(['plan_code' => 'La formule Solo est gratuite : résiliez votre abonnement pour y revenir.']);
        $this->subscribe('platine')->assertJsonValidationErrors(['plan_code' => 'Formule inconnue.']);
        $this->postJson('/api/v1/promoter/payments', ['plan_code' => 'pro', 'installments' => 2, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertJsonValidationErrors(['installments' => 'Seuls un acompte ou des échéances peuvent être réglés en plusieurs fois.']);
    }

    public function test_changing_plan_starts_a_new_monthly_period(): void
    {
        $this->subscribe('equipe')->assertCreated();
        $this->travel(10)->days();
        $this->actingAsPromoter($this->promoter);

        $this->subscribe('pro')->assertCreated()->assertJsonPath('data.message', 'Votre formule Pro est active. Invitez jusqu’à 14 collaborateurs.');

        $subscription = Subscription::query()->with(['plan', 'previousPlan'])->sole();
        $this->assertSame(['pro', 'equipe'], [$subscription->plan->code, $subscription->previousPlan->code]);
        $this->assertSame(today()->toDateString(), $subscription->current_period_start->toDateString());
        $this->assertSame(2, $subscription->invoices()->where('status', SubscriptionInvoiceStatus::Payee)->count());
        $this->getJson('/api/v1/promoter/subscription')->assertJsonPath('data.seats.label', '1 / 15 utilisateurs');
    }

    public function test_cancellation_keeps_the_plan_until_the_end_of_the_paid_period(): void
    {
        $this->subscribe('equipe')->assertCreated();
        $end = CarbonImmutable::today()->addMonthNoOverflow()->subDay();

        $this->postJson('/api/v1/promoter/subscription/cancel', ['reason' => 'Chantier terminé'])
            ->assertOk()
            ->assertJsonPath('message', 'Abonnement résilié · votre formule Équipe reste active jusqu’au '.$end->locale('fr')->translatedFormat('j M Y').'.')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.status.value', 'resiliation_programmee')
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.next_due_date', null)
            ->assertJsonPath('data.cancellation.ends_on', $end->toDateString());
        $this->postJson('/api/v1/promoter/subscription/cancel')->assertJsonValidationErrors(['subscription' => 'La résiliation est déjà programmée.']);

        $this->artisan('ochantier:renew-subscriptions')->expectsOutput('0 facture(s) émise(s), 0 abonnement(s) en retard, 0 expiré(s), 0 résilié(s).')->assertSuccessful();

        $this->travelTo($end->addDay()->setTime(9, 0));
        $this->artisan('ochantier:renew-subscriptions')->expectsOutput('0 facture(s) émise(s), 0 abonnement(s) en retard, 0 expiré(s), 1 résilié(s).')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Resiliee, Subscription::query()->sole()->status);
        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/subscription')->assertJsonPath('data.active', false)->assertJsonPath('data.plan.code', 'solo');
    }

    public function test_an_unpaid_renewal_becomes_late_then_expires(): void
    {
        $this->subscribe('equipe')->assertCreated();
        $renewal = CarbonImmutable::today()->addMonthNoOverflow();

        $this->travelTo($renewal->setTime(1, 0));
        $this->artisan('ochantier:renew-subscriptions')->expectsOutput('1 facture(s) émise(s), 1 abonnement(s) en retard, 0 expiré(s), 0 résilié(s).')->assertSuccessful();
        $this->actingAsPromoter($this->promoter);

        $invoice = $this->getJson('/api/v1/promoter/subscription')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.status', ['value' => 'en_retard', 'label' => 'Paiement en retard'])
            ->assertJsonPath('data.open_invoice.amount', 15000)
            ->assertJsonPath('data.open_invoice.status.value', 'en_retard')
            ->json('data.open_invoice');

        $this->postJson('/api/v1/promoter/payments', ['subscription_invoice_id' => $invoice['id'], 'operator_code' => 'wave', 'dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie');
        $this->getJson('/api/v1/promoter/subscription')
            ->assertJsonPath('data.status.value', 'actif')
            ->assertJsonPath('data.open_invoice', null)
            ->assertJsonPath('data.period.start', $renewal->toDateString())
            ->assertJsonCount(2, 'data.invoices');
        $this->postJson('/api/v1/promoter/payments', ['subscription_invoice_id' => $invoice['id'], 'operator_code' => 'wave', 'dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertJsonValidationErrors(['subscription_invoice_id' => 'Cette facture est déjà réglée.']);

        $next = $renewal->addMonthNoOverflow();
        $this->travelTo($next->setTime(1, 0));
        $this->artisan('ochantier:renew-subscriptions')->assertSuccessful();
        $this->travelTo($next->addDays(8)->setTime(1, 0));
        $this->artisan('ochantier:renew-subscriptions')->expectsOutput('0 facture(s) émise(s), 0 abonnement(s) en retard, 1 expiré(s), 0 résilié(s).')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expiree, Subscription::query()->sole()->status);
        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/subscription')->assertJsonPath('data.active', false);
    }

    public function test_cancellation_and_smaller_plans_are_refused_while_collaborators_remain(): void
    {
        $this->subscribe('equipe')->assertCreated();
        $organization = Organization::query()->create(['owner_user_id' => $this->promoter->id, 'legal_name' => 'Yao Construction']);
        $organization->members()->create(['user_id' => User::factory()->promoter()->create()->id, 'role' => OrganizationRole::ChefProjet, 'status' => OrganizationMemberStatus::Invite, 'invited_by_user_id' => $this->promoter->id]);

        $this->getJson('/api/v1/promoter/subscription')
            ->assertJsonPath('data.seats.label', '2 / 5 utilisateurs')
            ->assertJsonPath('data.plans.0.can_choose', false)
            ->assertJsonPath('data.plans.0.hint', 'Retirez d’abord les 1 utilisateur(s) invité(s).');

        $this->postJson('/api/v1/promoter/subscription/cancel')
            ->assertJsonValidationErrors(['subscription' => 'Retirez d’abord les 1 utilisateur(s) invité(s).']);
    }

    private function subscribe(string $plan): TestResponse
    {
        return $this->postJson('/api/v1/promoter/payments', ['plan_code' => $plan, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634']);
    }
}
