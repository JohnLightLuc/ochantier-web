<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    public function test_overview_links_team_payments_and_offers(): void
    {
        $this->get('/chantiers/residence-les-oliviers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sites/Show')
                ->where('tab', '')
                ->where('site.reference', 'CH-2026-0101')
                ->where('site.promoter', 'SICOGI')
                ->has('kpis', 4)
                ->where('kpis.0.value', '28 / 30')
                ->where('tabCounts.incidents', 1)
                ->where('tabCounts.documents', 2)
                ->has('milestones', 5)
                ->where('milestones.2.status', 'Réalisé')
                ->has('team', 9)
                ->has('payments', 3)
                ->where('payments.0.href', '/promoteurs/sicogi/paiements/PAY-260922-1842')
                ->where('promoterHref', '/promoteurs/sicogi')
                ->has('alerts', 1)
                ->where('header.crumbs.1.label', 'Résidence Les Oliviers')
            );
    }

    public function test_tabs_render_and_unknown_tab_is_not_found(): void
    {
        foreach (['equipe', 'incidents', 'documents', 'historique'] as $tab) {
            $this->get("/chantiers/residence-les-oliviers/{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab)->where('header.crumbs.2.href', null));
        }

        $this->get('/chantiers/residence-les-oliviers/finances')->assertNotFound();
        $this->get('/chantiers/inconnu')->assertNotFound();
    }

    public function test_status_banner_and_late_alert(): void
    {
        $this->get('/chantiers/tour-ivoire-business')->assertInertia(fn (Assert $page) => $page
            ->where('banner.title', 'Chantier suspendu.')
            ->where('alerts.0.tone', 'danger')
            ->where('alerts.1.title', 'Retard sur le planning'));

        $this->get('/chantiers/residence-bingerville-lot-b')->assertInertia(fn (Assert $page) => $page->where('banner.title', 'Travaux terminés.')->where('acceptanceVerified', false));
        $this->get('/chantiers/tour-horizon')->assertInertia(fn (Assert $page) => $page->where('promoterHref', '/promoteurs?q=Ivoire%20Properties')->where('payments', []));
    }

    public function test_sheet_and_demo_document_downloads(): void
    {
        $this->get('/chantiers/residence-les-oliviers/fiche')->assertOk()->assertDownload('chantier-CH-2026-0101.txt');

        $site = Site::where('slug', 'residence-les-oliviers')->first();
        $permit = $site->documents()->where('type', 'building_permit')->first();
        $report = $site->documents()->where('type', 'acceptance_report')->first();

        $this->get("/chantiers/residence-les-oliviers/documents/{$permit->id}/telechargement")->assertOk()->assertDownload('permis-de-construire-residence-les-oliviers.txt');
        $this->get("/chantiers/residence-les-oliviers/documents/{$report->id}/telechargement")->assertNotFound();
    }
}
