<?php

namespace Tests\Feature\Admin;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\PromoterSubscriptions;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Abonnements.
 */
class SubscriptionAdminTest extends PromoterTestCase
{
    private Admin $finance;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, CommercialOfferSeeder::class]);
        $this->finance = Admin::factory()->role('finance')->create();
        $this->today = CarbonImmutable::today();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function plan(string $code): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', $code)->firstOrFail();
    }

    private function subscription(string $plan = 'equipe', SubscriptionStatus $status = SubscriptionStatus::Active, ?CarbonImmutable $end = null): Subscription
    {
        $end ??= $this->today->addDays(10);
        $organization = Organization::query()->where('owner_user_id', $this->promoter->id)->first();

        return Subscription::query()->create([
            'reference' => 'ABO-2026-'.fake()->unique()->numerify('######'),
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'organization_id' => $organization?->id,
            'plan_id' => $this->plan($plan)->id,
            'status' => $status,
            'started_at' => $end->subMonth(),
            'current_period_start' => $end->subMonth()->addDay(),
            'current_period_end' => $end,
            'next_due_date' => $end->addDay(),
            'auto_renew' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function collaborator(): array
    {
        $organization = Organization::query()->where('owner_user_id', $this->promoter->id)->first()
            ?? Organization::query()->create(['legal_name' => 'BTP Plateau', 'owner_user_id' => $this->promoter->id]);
        $user = User::factory()->create();
        $organization->members()->create(['user_id' => $user->id, 'role' => OrganizationRole::ChefProjet, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);

        return [$user, $organization];
    }

    private function url(Subscription $subscription, string $action = ''): string
    {
        return "/api/v1/admin/subscriptions/{$subscription->id}".($action ? "/{$action}" : '');
    }

    private function invoice(Subscription $subscription, SubscriptionPlan $plan, SubscriptionInvoiceStatus $status = SubscriptionInvoiceStatus::EnRetard): SubscriptionInvoice
    {
        $start = CarbonImmutable::parse($subscription->current_period_end)->addDay();

        return $subscription->invoices()->create(['reference' => 'FAB-2026-'.fake()->unique()->numerify('######'), 'plan_id' => $plan->id, 'label' => 'Abonnement '.$plan->name, 'period_start' => $start, 'period_end' => $start->addMonth()->subDay(), 'amount' => $plan->price_amount, 'due_date' => $start, 'status' => $status]);
    }

    public function test_the_list_shows_subscriptions_with_their_indicators_and_filters(): void
    {
        $subscription = $this->subscription();
        $this->collaborator();

        $this->as($this->finance)->getJson('/api/v1/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.plan.code', 'equipe')
            ->assertJsonPath('data.0.seats_used', 2)
            ->assertJsonPath('data.0.status.value', 'active')
            ->assertJsonPath('meta.counts.paying', 1)
            ->assertJsonPath('meta.counts.monthly_revenue', 15000)
            ->assertJsonPath('meta.plans.0.code', 'equipe');

        $this->getJson('/api/v1/admin/subscriptions?plan=pro')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/subscriptions?status=suspendue')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/subscriptions?search={$subscription->reference}")->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString($subscription->reference, $this->get('/api/v1/admin/subscriptions/export')->assertOk()->streamedContent());

        $this->as($this->finance)->getJson($this->url($subscription))
            ->assertOk()
            ->assertJsonCount(2, 'data.users')
            ->assertJsonPath('data.users.0.role', 'Propriétaire')
            ->assertJsonPath('data.can.suspend', true)
            ->assertJsonPath('data.can.remind', false)
            ->assertJsonPath('data.can.change_plan', true);
    }

    public function test_support_reads_but_cannot_act(): void
    {
        $subscription = $this->subscription();
        $support = Admin::factory()->role('support')->create();

        $this->as($support)->getJson($this->url($subscription))->assertOk();
        $this->postJson($this->url($subscription, 'suspension'), ['reason' => 'Fraude suspectée'])->assertForbidden();
        $this->getJson('/api/v1/admin/subscriptions/export')->assertForbidden();
        $this->postJson('/api/v1/admin/subscription-plans', [])->assertForbidden();
    }

    public function test_an_unpaid_invoice_is_reminded_once_a_day(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::EnRetard, $this->today->subDays(2));
        $this->invoice($subscription, $this->plan('equipe'));

        $this->as($this->finance)->postJson($this->url($subscription, 'remind'))->assertOk()->assertJsonPath('message', 'Promoteur relancé.');
        $this->postJson($this->url($subscription, 'remind'))->assertUnprocessable()->assertJsonValidationErrors('subscription');

        $this->assertSame(1, Notification::query()->where('event_code', 'subscription.reminder')->count());
    }

    public function test_a_scheduled_plan_is_billed_at_renewal_and_applied_once_paid(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::Active, $this->today->addDay());

        $this->as($this->finance)->postJson($this->url($subscription, 'plan-change'), ['plan_code' => 'pro'])
            ->assertOk()
            ->assertJsonPath('data.pending_plan.code', 'pro')
            ->assertJsonPath('data.pending_plan.effective_at', $this->today->addDays(2)->toDateString());

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'equipe')
            ->assertJsonPath('data.pending_change.plan', 'Pro');

        app(PromoterSubscriptions::class)->renew($this->today->addDays(3));

        $subscription->refresh();
        $invoice = $subscription->invoices()->sole();
        $this->assertSame('equipe', $subscription->plan->code);
        $this->assertSame(35000, $invoice->amount);
        $this->assertSame($this->plan('pro')->id, $invoice->plan_id);

        $this->postJson('/api/v1/promoter/payments', ['subscription_invoice_id' => $invoice->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('pro', $subscription->plan->code);
        $this->assertNull($subscription->pending_plan_id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('equipe', $subscription->previousPlan->code);
    }

    public function test_a_plan_change_is_refused_when_it_cannot_apply(): void
    {
        $subscription = $this->subscription('pro');
        $url = $this->url($subscription, 'plan-change');

        $this->as($this->finance)->postJson($url, ['plan_code' => 'solo'])->assertUnprocessable()->assertJsonValidationErrors('subscription');
        $this->postJson($url, ['plan_code' => 'pro'])->assertUnprocessable();

        foreach (range(1, 6) as $i) {
            $this->collaborator();
        }

        $this->postJson($url, ['plan_code' => 'equipe'])->assertUnprocessable()->assertJsonPath('errors.subscription.0', 'Ce compte a 7 utilisateurs : la formule Équipe en autorise 5.');

        $this->invoice($subscription, $this->plan('pro'), SubscriptionInvoiceStatus::APayer);
        $this->postJson($url, ['plan_code' => 'entreprise'])->assertUnprocessable()->assertJsonPath('errors.subscription.0', 'Une facture est en attente : elle doit être réglée avant un changement de formule.');
    }

    public function test_a_scheduled_change_can_be_cancelled_and_is_dropped_if_the_team_outgrows_it(): void
    {
        $subscription = $this->subscription('pro', SubscriptionStatus::Active, $this->today->addDay());

        $this->as($this->finance)->postJson($this->url($subscription, 'plan-change'), ['plan_code' => 'equipe'])->assertOk();
        $this->deleteJson($this->url($subscription, 'plan-change'))->assertOk()->assertJsonPath('data.pending_plan', null);
        $this->deleteJson($this->url($subscription, 'plan-change'))->assertUnprocessable();

        $this->postJson($this->url($subscription, 'plan-change'), ['plan_code' => 'equipe'])->assertOk();
        foreach (range(1, 5) as $i) {
            $this->collaborator();
        }

        app(PromoterSubscriptions::class)->renew($this->today->addDays(3));

        $subscription->refresh();
        $this->assertNull($subscription->pending_plan_id);
        $this->assertSame(35000, $subscription->invoices()->sole()->amount);
    }

    public function test_suspension_cuts_collaborators_and_blocks_payment_until_reactivation(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::EnRetard, $this->today->subDays(20));
        $invoice = $this->invoice($subscription, $this->plan('equipe'));
        [$collaborator] = $this->collaborator();

        $this->as($this->finance)->postJson($this->url($subscription, 'suspension'), ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url($subscription, 'suspension'), ['reason' => 'Fraude suspectée'])->assertOk()->assertJsonPath('data.status.value', 'suspendue');

        $this->actingAsPromoter($collaborator)->getJson('/api/v1/promoter/sites')->assertForbidden();

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/subscription')
            ->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.plans.1.can_choose', false);
        $this->postJson('/api/v1/promoter/payments', ['plan_code' => 'pro', 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertUnprocessable()->assertJsonValidationErrors('subscription');

        app(PromoterSubscriptions::class)->renew();
        $this->assertSame(SubscriptionStatus::Suspendue, $subscription->fresh()->status);

        $this->as($this->finance)->postJson($this->url($subscription, 'reactivation'))->assertOk()->assertJsonPath('data.status.value', 'en_retard');
        $this->assertTrue($invoice->fresh()->due_date->isToday());

        app(PromoterSubscriptions::class)->renew();
        $this->assertSame(SubscriptionStatus::EnRetard, $subscription->fresh()->status);

        $this->actingAsPromoter($collaborator)->getJson('/api/v1/promoter/sites')->assertOk();
    }

    public function test_a_period_that_ended_during_a_suspension_gets_a_full_grace_period(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::Active, $this->today->subDays(20));
        $this->as($this->finance)->postJson($this->url($subscription, 'suspension'), ['reason' => 'Contrôle du compte'])->assertOk();
        $this->postJson($this->url($subscription, 'reactivation'))->assertOk()->assertJsonPath('data.status.value', 'active');

        app(PromoterSubscriptions::class)->renew();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::EnRetard, $subscription->status);
        $this->assertTrue($subscription->invoices()->sole()->due_date->isToday());
    }

    public function test_termination_honours_the_paid_period_and_can_be_undone(): void
    {
        $subscription = $this->subscription();
        [$collaborator] = $this->collaborator();

        $this->as($this->finance)->postJson($this->url($subscription, 'termination'), ['reason' => 'Demande du promoteur'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'active')
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.can.resume', true);
        $this->postJson($this->url($subscription, 'termination'), ['reason' => 'Demande du promoteur'])->assertUnprocessable();

        $this->deleteJson($this->url($subscription, 'termination'))->assertOk()->assertJsonPath('data.auto_renew', true);
        $this->postJson($this->url($subscription, 'termination'), ['reason' => 'Demande du promoteur'])->assertOk();

        $this->actingAsPromoter($collaborator)->getJson('/api/v1/promoter/sites')->assertOk();

        app(PromoterSubscriptions::class)->renew($this->today->addDays(11));
        $this->assertSame(SubscriptionStatus::Resiliee, $subscription->fresh()->status);

        $this->actingAsPromoter($collaborator)->getJson('/api/v1/promoter/sites')->assertForbidden();
        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/sites')->assertOk();
    }

    public function test_a_late_or_suspended_subscription_ends_at_once(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::EnRetard, $this->today->subDays(2));
        $invoice = $this->invoice($subscription, $this->plan('equipe'));

        $this->as($this->finance)->postJson($this->url($subscription, 'termination'), ['reason' => 'Impayé non régularisé'])->assertOk()->assertJsonPath('data.status.value', 'resiliee');
        $this->assertSame(SubscriptionInvoiceStatus::Annulee, $invoice->fresh()->status);

        $suspended = $this->subscription('pro', SubscriptionStatus::Suspendue);
        $this->postJson($this->url($suspended, 'termination'), ['reason' => 'Fraude confirmée'])->assertOk()->assertJsonPath('data.status.value', 'resiliee');
    }

    public function test_plans_are_created_updated_retired_and_restored(): void
    {
        $this->as($this->finance)->postJson('/api/v1/admin/subscription-plans', ['name' => 'Équipe', 'price_amount' => 500, 'max_users' => 1, 'features' => [], 'is_featured' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price_amount', 'max_users', 'features']);

        $id = $this->postJson('/api/v1/admin/subscription-plans', ['name' => 'Chantier+', 'price_amount' => 25000, 'max_users' => 8, 'features' => ['Rapports'], 'is_featured' => true])
            ->assertCreated()
            ->assertJsonPath('data.code', 'chantier')
            ->json('data.id');
        $this->assertSame(1, SubscriptionPlan::query()->where('is_featured', true)->count());

        $this->getJson('/api/v1/admin/subscription-plans')->assertOk()->assertJsonCount(5, 'data');

        $solo = $this->plan('solo');
        $this->patchJson("/api/v1/admin/subscription-plans/{$solo->id}", ['name' => 'Solo', 'price_amount' => 2000, 'max_users' => 2, 'features' => ['X'], 'is_featured' => false])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->postJson("/api/v1/admin/subscription-plans/{$solo->id}/retire")->assertUnprocessable();

        $this->postJson("/api/v1/admin/subscription-plans/{$id}/retire")->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAsPromoter($this->promoter)->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'chantier', 'operator_code' => 'orange_money'])->assertUnprocessable();
        $this->as($this->finance)->postJson("/api/v1/admin/subscription-plans/{$id}/restore")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_new_price_applies_at_renewal_and_seats_cannot_drop_below_a_subscriber(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::Active, $this->today->addDay());
        $this->collaborator();
        $this->collaborator();
        $plan = $this->plan('equipe');
        $body = ['name' => 'Équipe', 'price_amount' => 18000, 'max_users' => 2, 'features' => ['Rapports'], 'is_featured' => true];

        $this->as($this->finance)->patchJson("/api/v1/admin/subscription-plans/{$plan->id}", $body)
            ->assertUnprocessable()
            ->assertJsonPath('errors.max_users.0', 'Un abonné compte déjà 3 utilisateurs sur cette formule.');

        $this->patchJson("/api/v1/admin/subscription-plans/{$plan->id}", [...$body, 'max_users' => 5])->assertOk()->assertJsonPath('data.subtitle', 'Jusqu’à 5 utilisateurs');

        $this->assertSame(1, Notification::query()->where('event_code', 'subscription.updated')->count());

        app(PromoterSubscriptions::class)->renew($this->today->addDays(3));
        $this->assertSame(18000, $subscription->invoices()->sole()->amount);
    }

    public function test_a_retired_plan_stays_with_its_subscribers(): void
    {
        $subscription = $this->subscription('equipe', SubscriptionStatus::Active, $this->today->addDay());
        $plan = $this->plan('equipe');

        $this->as($this->finance)->postJson("/api/v1/admin/subscription-plans/{$plan->id}/retire")->assertOk();
        $this->postJson($this->url($this->subscription('pro', SubscriptionStatus::Expiree), 'plan-change'), ['plan_code' => 'equipe'])->assertUnprocessable();

        app(PromoterSubscriptions::class)->renew($this->today->addDays(3));
        $this->assertSame($plan->id, $subscription->invoices()->sole()->plan_id);
    }
}
