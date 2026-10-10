<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sidebar_module_has_a_page(): void
    {
        $user = User::factory()->create();

        foreach (config('admin.modules') as $module) {
            $this->actingAs($user)->get($module['path'])->assertOk();
        }
    }

    public function test_module_deep_links_render_the_placeholder(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/chantiers/ch1/pointages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ModulePlaceholder')
                ->where('module.key', 'chantiers')
            );
    }

    public function test_unknown_paths_return_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/inconnu')->assertNotFound();
    }
}
