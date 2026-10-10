<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/connexion');
    }

    public function test_dashboard_renders_with_shared_navigation(): void
    {
        $user = User::factory()->create(['name' => 'N. Yao']);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('kpis', 4)
                ->where('activity.range', '7j')
                ->where('finance.range', '7j')
                ->has('recentSites', 4)
                ->where('auth.user.initials', 'NY')
                ->has('navigation', count(config('admin.modules')))
                ->where('navigation.7.badge', 18)
            );
    }

    public function test_activity_and_finance_ranges_can_be_selected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/?periode=30j&flux=auj')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.range', '30j')
                ->has('activity.active', 7)
                ->where('finance.range', 'auj')
            );
    }

    public function test_custom_activity_range_generates_a_series(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/?periode=custom&du=2026-09-01&au=2026-09-21')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.range', 'custom')
                ->where('activity.from', '2026-09-01')
                ->has('activity.labels', 7)
                ->has('activity.active', 7)
            );
    }

    public function test_inverted_custom_range_returns_no_data(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/?periode=custom&du=2026-09-21&au=2026-09-01')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.range', 'custom')
                ->has('activity.labels', 0)
            );
    }

    public function test_unknown_range_falls_back_to_seven_days(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/?periode=nimporte&flux=quoi')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.range', '7j')
                ->where('finance.range', '7j')
            );
    }
}
