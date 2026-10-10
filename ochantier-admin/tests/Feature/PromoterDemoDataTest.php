<?php

namespace Tests\Feature;

use App\Models\Promoter;
use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PromoterDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PromoterSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function payment(string $ref): array
    {
        return collect(Promoter::firstWhere('slug', 'sicogi')->demo('payments'))->firstWhere('ref', $ref);
    }

    public function test_site_page_lists_linked_offers_and_payments(): void
    {
        $this->get('/promoteurs/sicogi/chantiers/ch1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Promoters/Site')
                ->where('site.name', 'Résidence Les Oliviers')
                ->where('paid', 3750000 + 280000)
                ->has('team', 4)
                ->where('milestones.3.state', 'current')
            );

        $this->get('/promoteurs/sicogi/chantiers/ch99')->assertNotFound();
    }

    public function test_payment_page_shows_split_and_history(): void
    {
        $this->get('/promoteurs/sicogi/paiements/PAY-260922-1842')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Promoters/Payment')
                ->where('split', ['commission' => 300000, 'fee' => 37500, 'net' => 3412500])
                ->where('history.3.label', 'Paiement réussi')
            );
    }

    public function test_payment_to_authorize_can_be_authorized_once(): void
    {
        $this->post('/promoteurs/sicogi/paiements/PAY-260918-1702/autorisation')->assertRedirect();
        $this->assertSame('Réussi', $this->payment('PAY-260918-1702')['status']);

        $this->post('/promoteurs/sicogi/paiements/PAY-260918-1702/autorisation')->assertStatus(422);
    }

    public function test_failed_payment_can_be_retried(): void
    {
        $this->post('/promoteurs/sicogi/paiements/PAY-260910-1590/relance')->assertRedirect();
        $this->assertSame('En traitement', $this->payment('PAY-260910-1590')['status']);
    }

    public function test_receipt_is_downloadable(): void
    {
        $this->get('/promoteurs/sicogi/paiements/PAY-260922-1842/recu')
            ->assertOk()
            ->assertSee('3 412 500 FCFA');
    }

    public function test_offer_and_review_moderation(): void
    {
        $this->post('/promoteurs/sicogi/offres/of3/validation')->assertRedirect();
        $this->post('/promoteurs/sicogi/offres/of1/validation')->assertStatus(422);
        $this->patch('/promoteurs/sicogi/avis/av3', ['status' => 'Publié'])->assertRedirect();
        $this->patch('/promoteurs/sicogi/avis/av3', ['status' => 'Supprimé'])->assertSessionHasErrors('status');

        $promoter = Promoter::firstWhere('slug', 'sicogi');
        $this->assertSame('Validée', collect($promoter->demo('offers'))->firstWhere('id', 'of3')['status']);
        $this->assertSame('Publié', collect($promoter->demo('reviews'))->firstWhere('id', 'av3')['status']);
    }
}
