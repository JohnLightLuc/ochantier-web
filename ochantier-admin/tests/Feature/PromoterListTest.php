<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Promoter;
use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PromoterListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PromoterSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_list_shows_kpis_and_first_page(): void
    {
        $this->get('/promoteurs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Promoters/Index')
                ->where('kpis', ['total' => 7, 'active' => 5, 'pending' => 1, 'suspended' => 1])
                ->has('promoters.data', 7)
                ->where('promoters.data.0.slug', 'sicogi')
                ->where('promoters.data.0.kyc.label', 'Expiré')
                ->where('promoters.data.0.sitesCount', 7)
                ->where('todo', ['incomplete' => 1, 'expiredDocuments' => 3, 'expiredPromoters' => 2, 'reported' => 3, 'total' => 4])
            );
    }

    public function test_list_can_be_searched_and_filtered(): void
    {
        $this->get('/promoteurs?q=koné')
            ->assertInertia(fn (Assert $page) => $page->has('promoters.data', 1)->where('promoters.data.0.slug', 'mk-immobilier'));

        $this->get('/promoteurs?statut=suspended')
            ->assertInertia(fn (Assert $page) => $page->has('promoters.data', 1)->where('promoters.data.0.slug', 'batir-afrique'));

        $this->get('/promoteurs?kyc=missing')
            ->assertInertia(fn (Assert $page) => $page->has('promoters.data', 1)->where('promoters.data.0.slug', 'horizon-btp'));

        $this->get('/promoteurs?kyc=verified')
            ->assertInertia(fn (Assert $page) => $page->has('promoters.data', 4));

        $this->get('/promoteurs?inscription=2025')
            ->assertInertia(fn (Assert $page) => $page->has('promoters.data', 2));
    }

    public function test_quick_filters_match_the_todo_counters(): void
    {
        $this->get('/promoteurs?rapide=incomplets')->assertInertia(fn (Assert $page) => $page->has('promoters.data', 1));
        $this->get('/promoteurs?rapide=expirees')->assertInertia(fn (Assert $page) => $page->has('promoters.data', 2));
        $this->get('/promoteurs?rapide=signales')->assertInertia(fn (Assert $page) => $page->has('promoters.data', 3));
        $this->get('/promoteurs?rapide=tous')->assertInertia(fn (Assert $page) => $page->has('promoters.data', 4));
    }

    public function test_list_can_be_sorted_and_paginated(): void
    {
        Promoter::factory()->count(3)->withDocuments()->create();

        $this->get('/promoteurs?tri=entreprise&sens=desc')
            ->assertInertia(fn (Assert $page) => $page->where('promoters.lastPage', 2)->has('promoters.data', 8));

        $this->get('/promoteurs?page=2')
            ->assertInertia(fn (Assert $page) => $page->where('promoters.currentPage', 2)->has('promoters.data', 2)->where('promoters.from', 9));
    }

    public function test_export_returns_a_csv_of_the_selection(): void
    {
        $ids = Promoter::whereIn('slug', ['sicogi', 'horizon-btp'])->pluck('id')->implode(',');

        $response = $this->get("/promoteurs/export?ids={$ids}")->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Promoteur;Entreprise', $csv);
        $this->assertStringContainsString('SICOGI', $csv);
        $this->assertStringContainsString('"Horizon BTP"', $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1);
    }

    public function test_kyc_status_uses_the_most_severe_required_document(): void
    {
        $promoter = Promoter::factory()->withDocuments(DocumentStatus::Verified)->create();
        $promoter->documents()->where('type', 'tax_certificate')->update(['status' => DocumentStatus::ToReview]);
        $promoter->documents()->where('type', 'additional')->update(['status' => DocumentStatus::Missing]);

        $this->assertSame(DocumentStatus::ToReview, $promoter->fresh()->kycStatus());
    }
}
