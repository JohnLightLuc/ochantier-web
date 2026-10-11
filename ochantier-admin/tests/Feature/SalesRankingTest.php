<?php

namespace Tests\Feature;

use App\Models\SalesAgent;
use App\Models\SalesRanking;
use App\Models\User;
use Database\Seeders\SalesAgentSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([WorkerSeeder::class, SalesAgentSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_current_ranking_is_provisional_and_sorted_by_score(): void
    {
        $this->get('/commerciaux/classement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SalesAgents/Ranking')
                ->where('month', '2026-09')
                ->where('closed', false)
                ->where('published', false)
                ->has('rows', 7)
                ->where('rows.0.rank', 1)
                ->where('topSlug', 'adama-coulibaly')
                ->has('history', 5)
                ->where('rows', fn ($rows) => collect($rows)->pluck('score')->sortDesc()->values()->all() === collect($rows)->pluck('score')->values()->all())
            );
    }

    public function test_suspended_agent_is_ranked_but_not_eligible(): void
    {
        $this->get('/commerciaux/classement/2026/8')->assertInertia(fn (Assert $page) => $page
            ->where('closed', true)
            ->where('published', true)
            ->has('rows', 8)
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('slug', 'serge-koffi')['eligible'] === false)
        );
        $this->get('/commerciaux/classement/2025/1')->assertNotFound();
    }

    public function test_month_can_be_published_closed_and_exported(): void
    {
        $this->post('/commerciaux/classement/2026/9/publication')->assertRedirect();
        $this->assertTrue(SalesRanking::where('month', '2026-09')->first()->published);

        $this->post('/commerciaux/classement/2026/9/cloture')->assertRedirect();
        $this->assertNotNull(SalesRanking::where('month', '2026-09')->first()->closed_at);
        $this->assertSame(1, SalesAgent::where('slug', 'adama-coulibaly')->first()->rewards()->where('month', '2026-09')->count());
        $this->post('/commerciaux/classement/2026/9/cloture')->assertStatus(422);

        $response = $this->get('/commerciaux/classement/2026/9/export');
        $response->assertOk()->assertDownload('classement-2026-09.csv');
        $this->assertStringContainsString('1;"Adama Coulibaly"', $response->streamedContent());
    }
}
