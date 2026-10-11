<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, SubscriptionSeeder::class]);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function subscription(string $plan, string $status): Subscription
    {
        return Subscription::with('promoter')->where('plan_key', $plan)->where('status', $status)->firstOrFail();
    }

    public function test_list_kpis_filters_and_plans_tab(): void
    {
        $this->get('/abonnements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->where('tab', 'abonnes')
                ->where('kpis.0.value', '3')
                ->where('kpis.1.value', '50 000')
                ->where('kpis.3.value', '1')
                ->has('subscriptions.data', 7)
                ->has('plans', 3)
            );
        $this->get('/abonnements?statut=Impayé')->assertInertia(fn (Assert $page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.note', fn (string $n): bool => str_starts_with($n, 'En retard de')));
        $this->get('/abonnements?onglet=formules')->assertInertia(fn (Assert $page) => $page->where('tab', 'formules')->where('plans.1.subscribers', 3));
        $this->assertStringContainsString('Formule', $this->get('/abonnements/export')->assertOk()->streamedContent());
    }

    public function test_detail_page(): void
    {
        $s = $this->subscription('pro', 'Actif');

        $this->get("/abonnements/{$s->promoter->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Subscriptions/Show')->where('subscription.plan.key', 'pro')->has('plans', 3)->has('invoices', 3));
    }

    public function test_upgrade_is_immediate_with_prorata_and_downgrade_is_scheduled(): void
    {
        $s = $this->subscription('equipe', 'Actif');
        $base = "/abonnements/{$s->promoter->slug}";

        $this->post("{$base}/formule", ['plan' => 'pro'])->assertSessionHasNoErrors();
        $s = $s->fresh();
        $this->assertSame('pro', $s->plan_key);
        $invoice = $s->invoices()->firstOrFail();
        $this->assertSame('Prorata Équipe → Pro', $invoice->label);
        $this->assertSame('En attente', $invoice->status);
        $this->assertGreaterThan(0, $invoice->amount);

        $this->post("{$base}/formule", ['plan' => 'equipe'])->assertSessionHasNoErrors();
        $this->assertSame('pro', $s->fresh()->plan_key);
        $this->assertSame('equipe', $s->fresh()->pending_plan_key);
        $this->delete("{$base}/formule-programmee")->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->pending_plan_key);

        $pro = Subscription::with('promoter')->withCount('users')->where('plan_key', 'pro')->orderByDesc('users_count')->firstOrFail();
        $this->assertGreaterThan(5, $pro->users_count);
        $this->post("/abonnements/{$pro->promoter->slug}/formule", ['plan' => 'equipe'])->assertStatus(422);
    }

    public function test_payment_trial_suspension_and_termination(): void
    {
        $unpaid = $this->subscription('equipe', 'Impayé');
        $base = "/abonnements/{$unpaid->promoter->slug}";
        $this->post("{$base}/relance")->assertSessionHasNoErrors();
        $this->post("{$base}/paiement")->assertSessionHasNoErrors();
        $this->assertSame('Actif', $unpaid->fresh()->status);
        $this->assertSame(0, $unpaid->invoices()->where('status', 'Échouée')->count());

        $trial = $this->subscription('equipe', 'Essai');
        $due = $trial->next_due;
        $this->post("/abonnements/{$trial->promoter->slug}/essai")->assertSessionHasNoErrors();
        $this->assertTrue($trial->fresh()->next_due->equalTo($due->addDays(7)));

        $this->post("{$base}/suspension", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("{$base}/suspension", ['reason' => 'Impayé prolongé'])->assertSessionHasNoErrors();
        $this->post("{$base}/utilisateurs", ['name' => 'Awa Koné', 'channel' => 'SMS', 'role' => 'Technicien', 'contact' => '0707070707'])->assertStatus(422);
        $this->post("{$base}/reactivation")->assertSessionHasNoErrors();

        $this->post("{$base}/resiliation", ['reason' => 'Fin du projet'])->assertSessionHasNoErrors();
        $s = $unpaid->fresh();
        $this->assertSame('Résilié', $s->status);
        $this->assertSame('solo', $s->plan_key);
        $this->assertSame(1, $s->users()->count());
        $this->post("{$base}/restauration")->assertSessionHasNoErrors();
        $this->assertSame('Actif', $s->fresh()->status);
    }

    public function test_invitations_respect_the_plan_limit(): void
    {
        $s = $this->subscription('equipe', 'Actif');
        $base = "/abonnements/{$s->promoter->slug}";

        $this->post("{$base}/utilisateurs", ['name' => 'Awa Koné', 'channel' => 'E-mail', 'role' => 'Technicien', 'contact' => 'pas-un-email'])->assertSessionHasErrors('contact');
        $this->post("{$base}/utilisateurs", ['name' => 'Awa Koné', 'channel' => 'E-mail', 'role' => 'Technicien', 'contact' => 'awa@exemple.ci'])->assertSessionHasNoErrors();
        while ($s->users()->count() < 5) {
            $this->post("{$base}/utilisateurs", ['name' => 'Collaborateur', 'channel' => 'SMS', 'role' => 'Observateur', 'contact' => '0707070707']);
        }
        $this->post("{$base}/utilisateurs", ['name' => 'De trop', 'channel' => 'SMS', 'role' => 'Observateur', 'contact' => '0707070707'])->assertStatus(422);

        $owner = $s->users()->where('owner', true)->firstOrFail();
        $this->delete("{$base}/utilisateurs/{$owner->id}")->assertStatus(422);
        $guest = $s->users()->where('owner', false)->firstOrFail();
        $this->delete("{$base}/utilisateurs/{$guest->id}")->assertSessionHasNoErrors();
        $this->assertSame(4, $s->users()->count());
    }

    public function test_plans_are_created_updated_and_deleted_with_migration(): void
    {
        $this->post('/abonnements/formules', ['name' => 'Équipe', 'price' => 20000, 'max_users' => 8, 'popular' => 0, 'features' => 'X'])->assertSessionHasErrors('name');
        $this->post('/abonnements/formules', ['name' => 'Chantier+', 'price' => 500, 'max_users' => 8, 'popular' => 1, 'features' => 'X'])->assertSessionHasErrors('price');
        $this->post('/abonnements/formules', ['name' => 'Chantier+', 'price' => 25000, 'max_users' => 8, 'popular' => 1, 'features' => "Jusqu’à 8 utilisateurs\nRapports"])->assertSessionHasNoErrors();
        $plan = SubscriptionPlan::query()->where('key', 'chantier')->firstOrFail();
        $this->assertSame(['Jusqu’à 8 utilisateurs', 'Rapports'], $plan->features);
        $this->assertSame(1, SubscriptionPlan::query()->where('popular', true)->count());

        $this->put('/abonnements/formules/pro', ['name' => 'Pro', 'price' => 40000, 'max_users' => 3, 'popular' => 0, 'features' => 'X'])->assertSessionHasErrors('max_users');
        $this->put('/abonnements/formules/solo', ['name' => 'Solo', 'price' => 1000, 'max_users' => 2, 'popular' => 0, 'features' => 'X'])->assertStatus(422);
        $this->delete('/abonnements/formules/solo')->assertStatus(422);

        $this->delete('/abonnements/formules/pro', ['to' => 'equipe'])->assertSessionHasErrors('to');
        $this->delete('/abonnements/formules/pro', ['to' => 'chantier'])->assertSessionHasErrors('to');
        $this->delete('/abonnements/formules/equipe', ['to' => 'chantier'])->assertSessionHasNoErrors();
        $this->assertSame(0, Subscription::query()->where('plan_key', 'equipe')->count());
        $this->assertNull(SubscriptionPlan::query()->where('key', 'equipe')->first());
    }
}
