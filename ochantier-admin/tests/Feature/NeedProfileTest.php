<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NeedProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_overview_shows_coverage_alerts_and_lines(): void
    {
        $this->get('/besoins/bes-2026-0141')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Needs/Show')
                ->where('need.site.name', 'Résidence Les Oliviers')
                ->where('coverage.selected', 5)
                ->where('coverage.need', 9)
                ->where('kpis.3.value', '56 %')
                ->where('tabCounts.metiers', 4)
                ->where('alerts.0.key', 'demarrage')
                ->where('alerts.1.action.type', 'extend')
                ->has('lines', 2)
                ->where('lines.0.found', 5)
                ->has('offers', 2)
                ->has('chronology', 6)
                ->where('banner', null)
                ->where('actionRef.canMatch', true)
                ->where('header.crumbs.1.label', 'BES-2026-0141')
            );
    }

    public function test_tabs_render_and_found_workers_tab_requires_a_selection(): void
    {
        foreach (['metiers', 'affectations', 'documents', 'historique'] as $tab) {
            $this->get("/besoins/bes-2026-0141/{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab));
        }

        $this->get('/besoins/bes-2026-0141/affectations')->assertInertia(fn (Assert $page) => $page->has('assignments', 5));
        $this->get('/besoins/bes-2026-0142/affectations')->assertNotFound();
        $this->get('/besoins/bes-2026-0141/offres')->assertNotFound();
    }

    public function test_banners_follow_the_coverage_status(): void
    {
        $this->get('/besoins/bes-2026-0144')->assertInertia(fn (Assert $page) => $page->where('banner.title', 'Besoin comblé.')->where('banner.cta', true)->where('actionRef.done', true));
        $this->get('/besoins/bes-2026-0150')->assertInertia(fn (Assert $page) => $page->where('banner.title', 'Besoin expiré.')->has('actionRef.expiredOffers', 1));
        $this->get('/besoins/bes-2026-0153')->assertInertia(fn (Assert $page) => $page->where('banner.title', 'Besoin suspendu.')->where('alerts', []));
        $this->get('/besoins/bes-2026-0146/documents')->assertInertia(fn (Assert $page) => $page->where('alerts.0.title', 'Anomalie détectée')->where('documents.2.status.label', 'Manquant'));
    }

    public function test_sheet_download(): void
    {
        $this->get('/besoins/bes-2026-0141/fiche')->assertOk()->assertDownload('besoin-BES-2026-0141.txt');
    }
}
