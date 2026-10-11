<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NeedListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_tabs_quick_values_and_coverage_alerts(): void
    {
        $this->get('/besoins')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Needs/Index')
                ->where('tab', 'attente')
                ->where('tabs.0.count', 9)
                ->where('tabs.1.count', 3)
                ->where('tabs.2.count', 3)
                ->where('quick.0.value', '9')
                ->where('quick.4.value', '34')
                ->where('quick.7.value', '42 %')
                ->where('stats.delay', '5 j')
                ->where('todo', ['demarrage' => 5, 'critique' => 1, 'expire' => 4])
                ->has('needs.data', 8)
                ->where('needs.lastPage', 2)
                ->where('needs.data.0.reference', 'BES-2026-0141')
                ->where('needs.data.0.status.label', 'Partiellement couvert')
                ->has('filterDefs', 17)
            );
    }

    public function test_tabs_quick_filters_alerts_and_sorting(): void
    {
        $this->get('/besoins?onglet=expires')->assertInertia(fn (Assert $page) => $page->where('needs.total', 3)->where('needs.data.0.actions.expiredOffers.0.reference', 'BES-2026-0150/1'));
        $this->get('/besoins?onglet=suspendus&statut=suspended')->assertInertia(fn (Assert $page) => $page->where('needs.total', 1)->where('needs.data.0.slug', 'bes-2026-0153')->has('filterDefs', 18));
        $this->get('/besoins?rapide=urgents')->assertInertia(fn (Assert $page) => $page->where('needs.total', 3));
        $this->get('/besoins?alerte=critique')->assertInertia(fn (Assert $page) => $page->where('needs.total', 1)->where('needs.data.0.slug', 'bes-2026-0147'));
        $this->get('/besoins?q=BES-2026-0146')->assertInertia(fn (Assert $page) => $page->where('needs.total', 1));
        $this->get('/besoins?metier='.urlencode('Électricien'))->assertInertia(fn (Assert $page) => $page->where('needs.total', 2));
        $this->get('/besoins?anomalie=avec')->assertInertia(fn (Assert $page) => $page->where('needs.total', 1)->where('needs.data.0.slug', 'bes-2026-0146'));
        $this->get('/besoins?tri=urgence&sens=desc')->assertInertia(fn (Assert $page) => $page->where('needs.data.0.slug', 'bes-2026-0147'));
    }

    public function test_cards_view_and_csv_export(): void
    {
        $this->get('/besoins?vue=cartes')->assertInertia(fn (Assert $page) => $page->where('view', 'cartes')->has('cards', 9)->where('cards.0.alert.text', 'Démarrage proche sans effectif suffisant'));

        $response = $this->get('/besoins/export?onglet=expires');
        $response->assertOk()->assertDownload('besoins-expires-2026-09-23.csv');
        $this->assertStringContainsString('BES-2026-0150;"Cité Belle Rive"', $response->streamedContent());
        $this->assertStringNotContainsString('BES-2026-0141', $response->streamedContent());
    }
}
