<?php

namespace Tests\Feature;

use App\Models\Need;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NeedRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function needStatus(Need $need): string
    {
        return $need->fresh()->load('lines', 'offers.line', 'offers.assignments', 'assignments')->status();
    }

    public function test_expired_offer_is_refreshed(): void
    {
        $need = Need::factory()->withLine(found: 1, deadline: '2026-09-20')->create();
        $offer = $need->offers()->first();
        $this->assertSame(Need::EXPIRED, $this->needStatus($need));

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-01'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-10-20'])->assertSessionHasErrors('deadline');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-30'])->assertSessionHasNoErrors();

        $offer->refresh();
        $this->assertSame('2026-09-30', $offer->deadline->format('Y-m-d'));
        $this->assertSame(1, $offer->refreshes);
        $this->assertSame(Need::PARTIAL, $this->needStatus($need));
    }

    public function test_refreshing_an_offer_whose_start_has_passed_requires_a_new_start(): void
    {
        $need = Need::factory()->state(['starts_on' => '2026-09-22', 'ends_on' => '2026-10-20'])->withLine(deadline: '2026-09-18')->create();
        $offer = $need->offers()->first();

        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-28'])->assertSessionHasErrors('starts_on');
        $this->post("/besoins/{$need->slug}/offres/{$offer->id}/actualisation", ['deadline' => '2026-09-28', 'starts_on' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', $offer->fresh()->starts_on->format('Y-m-d'));
    }
}
