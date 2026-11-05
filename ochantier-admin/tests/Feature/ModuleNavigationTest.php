<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modules dont les pages lisent l’API (testés avec des réponses simulées, voir PromotersTest et WorkersTest).
     */
    private const API_MODULES = ['/promoteurs', '/ouvriers', '/commerciaux', '/chantiers', '/besoins', '/matching', '/affectations', '/pointages'];

    public function test_every_sidebar_module_has_a_page(): void
    {
        $user = User::factory()->create();

        foreach (config('admin.modules') as $module) {
            if (in_array($module['path'], self::API_MODULES, true)) {
                continue;
            }

            $this->actingAs($user)->get($module['path'])->assertOk();
        }
    }

    public function test_unknown_paths_return_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/inconnu')->assertNotFound();
    }
}
