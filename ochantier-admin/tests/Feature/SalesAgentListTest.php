<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SalesAgentSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesAgentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([WorkerSeeder::class, SalesAgentSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_kpis_follow_up_and_commercial_of_the_month(): void
    {
        $this->get('/commerciaux')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SalesAgents/Index')
                ->where('kpis.total', 13)
                ->where('kpis.active', 7)
                ->where('todo.objective', 4)
                ->where('todo.followUp', 5)
                ->where('todo.earnings', 7)
                ->where('todo.pending', 1551500)
                ->has('agents.data', 8)
                ->where('agents.lastPage', 2)
                ->where('cotm.month', '2026-09')
                ->where('cotm.closed', false)
                ->where('cotm.top.slug', 'adama-coulibaly')
                ->has('months', 9)
            );
    }

    public function test_commercial_of_the_month_can_target_a_closed_month(): void
    {
        $this->get('/commerciaux?mois=2026-08')->assertInertia(fn (Assert $page) => $page->where('cotm.month', '2026-08')->where('cotm.closed', true)->has('cotm.top.stats', 5));
        $this->get('/commerciaux?mois=2025-01')->assertInertia(fn (Assert $page) => $page->where('cotm.month', '2026-09'));
    }

    public function test_search_filters_and_quick_flags(): void
    {
        $this->get('/commerciaux?q=COM-015')->assertInertia(fn (Assert $page) => $page->has('agents.data', 1)->where('agents.data.0.slug', 'mariam-kone'));
        $this->get('/commerciaux?statut=suspended')->assertInertia(fn (Assert $page) => $page->has('agents.data', 1)->where('agents.data.0.slug', 'serge-koffi'));
        $this->get('/commerciaux?agence='.urlencode('Agence Marcory'))->assertInertia(fn (Assert $page) => $page->has('agents.data', 4));
        $this->get('/commerciaux?rapide=relances')->assertInertia(fn (Assert $page) => $page->has('agents.data', 5));
        $this->get('/commerciaux?rapide=objectifs&tri=performance&sens=desc')->assertInertia(fn (Assert $page) => $page->has('agents.data', 4)->where('agents.data.0.slug', 'adama-coulibaly'));
    }

    public function test_export_lists_agents_as_csv(): void
    {
        $response = $this->get('/commerciaux/export');

        $response->assertOk()->assertDownload('commerciaux.csv');
        $this->assertStringContainsString('COM-012;"Adama Coulibaly"', $response->streamedContent());
    }
}
