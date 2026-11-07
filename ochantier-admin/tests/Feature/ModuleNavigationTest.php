<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sidebar_module_has_a_page(): void
    {
        foreach (config('admin.modules') as $module) {
            $route = Route::getRoutes()->match(Request::create($module['path']));

            $this->assertNotNull($route->getName(), "Aucune page pour {$module['path']}");
        }
    }

    public function test_unknown_paths_return_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/inconnu')->assertNotFound();
    }
}
