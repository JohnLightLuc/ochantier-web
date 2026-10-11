<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_quick_kpis_and_alerts(): void
    {
        $this->get('/chantiers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sites/Index')
                ->where('view', 'tableau')
                ->has('kpis', 8)
                ->where('kpis.0.value', 15)
                ->where('kpis.1.value', 7)
                ->where('kpis.2.value', 2)
                ->where('kpis.3.value', 3)
                ->where('kpis.4.value', 6)
                ->where('kpis.5.value', 9)
                ->where('kpis.6.value', 6)
                ->where('kpis.7.value', 1)
                ->where('todo', ['retard' => 6, 'paiement' => 5, 'remplacement' => 6])
                ->has('sites.data', 8)
                ->where('sites.lastPage', 2)
                ->where('sites.data.0.reference', 'CH-2026-0101')
                ->where('cards', [])
                ->where('events', [])
            );
    }

    public function test_search_filters_quick_filters_and_sorting(): void
    {
        $this->get('/chantiers?q=CH-2026-0103')->assertInertia(fn (Assert $page) => $page->has('sites.data', 1)->where('sites.data.0.slug', 'cite-belle-rive'));
        $this->get('/chantiers?statut=suspended')->assertInertia(fn (Assert $page) => $page->has('sites.data', 1)->where('sites.data.0.slug', 'tour-ivoire-business'));
        $this->get('/chantiers?rapide=cloturer')->assertInertia(fn (Assert $page) => $page->has('sites.data', 1)->where('sites.data.0.slug', 'residence-bingerville-lot-b'));
        $this->get('/chantiers?alerte=paiement')->assertInertia(fn (Assert $page) => $page->where('sites.total', 5));
        $this->get('/chantiers?incident=avec&promoteur=SICOGI')->assertInertia(fn (Assert $page) => $page->where('sites.total', 4));
        $this->get('/chantiers?periode=a-venir')->assertInertia(fn (Assert $page) => $page->where('sites.total', 5));
        $this->get('/chantiers?tri=alerte&sens=desc')->assertInertia(fn (Assert $page) => $page->where('sites.data.0.slug', 'tour-ivoire-business')->where('sites.data.0.alert.label', 'Critique'));
    }

    public function test_dashboard_status_links_are_mapped_to_the_status_filter(): void
    {
        $this->get('/chantiers?filtre='.urlencode('En retard'))
            ->assertInertia(fn (Assert $page) => $page->where('filters.statut', 'late')->where('sites.total', 2));
    }

    public function test_cards_calendar_and_map_views(): void
    {
        $this->get('/chantiers?vue=cartes&rapide=retard')->assertInertia(fn (Assert $page) => $page->where('view', 'cartes')->has('cards', 6)->where('events', []));
        $this->get('/chantiers?vue=calendrier')->assertInertia(fn (Assert $page) => $page
            ->where('cards', [])
            ->where('events.0.kind', fn (string $kind): bool => in_array($kind, ['start', 'end', 'milestone', 'payment', 'urgent', 'replacement'], true))
            ->where('events', fn ($events): bool => collect($events)->contains(fn (array $e): bool => $e['kind'] === 'replacement' && $e['date'] === '2026-09-15')));
        $this->get('/chantiers?vue=carte')->assertInertia(fn (Assert $page) => $page->has('cards', 15)->has('communes.Cocody', 2));
    }

    public function test_export_lists_sites_as_csv(): void
    {
        $response = $this->get('/chantiers/export');

        $response->assertOk()->assertDownload('chantiers.csv');
        $this->assertStringContainsString('CH-2026-0101;"Résidence Les Oliviers";SICOGI;Cocody', $response->streamedContent());
    }
}
