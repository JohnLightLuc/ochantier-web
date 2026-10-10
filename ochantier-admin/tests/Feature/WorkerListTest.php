<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkerListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkerSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_kpis_todo_and_first_page(): void
    {
        $this->get('/ouvriers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workers/Index')
                ->where('kpis', ['total' => 16, 'available' => 6, 'onMission' => 3, 'toReview' => 2])
                ->where('todo', ['review' => 2, 'replacement' => 1, 'documents' => 6])
                ->has('workers.data', 8)
                ->where('workers.lastPage', 2)
                ->where('workers.data.0.code', 'OUV-0128')
            );
    }

    public function test_list_can_be_searched_by_name_phone_and_code(): void
    {
        $koffi = Worker::firstWhere('slug', 'koffi-adjoumani');
        $digits = substr(Worker::phoneDigits($koffi->phone), 2, 6);

        $this->get('/ouvriers?q=koffi adj')->assertInertia(fn (Assert $page) => $page->has('workers.data', 1)->where('workers.data.0.slug', 'koffi-adjoumani'));
        $this->get("/ouvriers?q={$digits}")->assertInertia(fn (Assert $page) => $page->where('workers.data.0.slug', 'koffi-adjoumani'));
        $this->get('/ouvriers?q=OUV-0316')->assertInertia(fn (Assert $page) => $page->has('workers.data', 1)->where('workers.data.0.slug', 'yao-kouame'));
    }

    public function test_list_filters_combine(): void
    {
        $this->get('/ouvriers?metier='.urlencode('Électricien'))->assertInertia(fn (Assert $page) => $page->has('workers.data', 2));
        $this->get('/ouvriers?niveau=expert')->assertInertia(fn (Assert $page) => $page->has('workers.data', 5));
        $this->get('/ouvriers?disponibilite=on_mission&cout=plus-11000')->assertInertia(fn (Assert $page) => $page->has('workers.data', 2));
        $this->get('/ouvriers?note=moins-4')->assertInertia(fn (Assert $page) => $page->has('workers.data', 2));
        $this->get('/ouvriers?rapide=remplacements')->assertInertia(fn (Assert $page) => $page->has('workers.data', 1)->where('workers.data.0.slug', 'siaka-bamba'));
        $this->get('/ouvriers?rapide=pieces')->assertInertia(fn (Assert $page) => $page->has('workers.data', 6));
    }

    public function test_list_can_be_sorted(): void
    {
        $this->get('/ouvriers?tri=cout&sens=desc')->assertInertia(fn (Assert $page) => $page->where('workers.data.0.slug', 'serge-nguessan'));
    }

    public function test_export_returns_selected_rows(): void
    {
        $ids = Worker::whereIn('slug', ['koffi-adjoumani', 'paul-ake'])->pluck('id')->implode(',');
        $csv = $this->get("/ouvriers/export?ids={$ids}")->assertOk()->streamedContent();

        $this->assertStringContainsString('OUV-0128', $csv);
        $this->assertStringContainsString('OUV-0087', $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1);
    }
}
