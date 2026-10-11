<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MatchingSeeder;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MatchingMainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class, MatchingSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_main_screen_proposes_profiles_for_every_open_position(): void
    {
        $this->get('/matching')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Matching/Index')
                ->where('kpis.0.value', '8')
                ->where('kpis.1.value', '9')
                ->where('kpis.2.value', '32')
                ->where('kpis.3.value', '86 %')
                ->has('needs', 8)
                ->has('groups', 11)
                ->where('groups.0.offer.reference', 'BES-2026-0147/1')
                ->where('groups.0.head.title', 'BES-2026-0147 · Résidence Les Oliviers')
                ->where('groups.0.chosen', 2)
                ->where('groups.0.picks.0.selected', true)
                ->where('sums.required', 32)
                ->where('toValidate', 27)
            );
    }

    public function test_a_need_coming_from_the_besoins_module_is_preselected(): void
    {
        $this->get('/matching?besoin=bes-2026-0141')->assertInertia(fn (Assert $page) => $page
            ->has('groups', 2)
            ->where('groups.0.offer.reference', 'BES-2026-0141/1')
            ->where('needs.0.on', false)
            ->where('currentOffer', fn ($id): bool => $id !== null));

        $this->get('/matching?besoins=')->assertInertia(fn (Assert $page) => $page->has('groups', 0));
        $this->get('/matching?metier=Plombier')->assertInertia(fn (Assert $page) => $page->has('needs', 1)->has('groups', 2));
        $this->get('/matching?alt='.'1&besoin=bes-2026-0141')->assertOk();
    }
}
