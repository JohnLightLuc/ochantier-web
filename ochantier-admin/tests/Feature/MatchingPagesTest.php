<?php

namespace Tests\Feature;

use App\Models\NeedOffer;
use App\Models\User;
use App\Support\MatchingEngine;
use Database\Seeders\MatchingSeeder;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MatchingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class, MatchingSeeder::class]);
        $this->actingAs(User::factory()->create());
    }

    private function offer(string $reference): NeedOffer
    {
        return NeedOffer::where('reference', $reference)->firstOrFail();
    }

    public function test_results_to_validate_history_and_analyses(): void
    {
        $this->get('/matching/resultats')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Matching/Results')
            ->where('rows', fn ($rows): bool => count($rows) > 50 && collect($rows)->every(fn (array $r): bool => ! $r['blocked']))
            ->where('rows.0.offer.reference', 'BES-2026-0147/1'));

        $this->get('/matching/a-valider')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Matching/ToValidate')
            ->has('rows', 11)
            ->where('rows', fn ($rows): bool => collect($rows)->contains(fn (array $r): bool => $r['offer']['reference'] === 'BES-2026-0143/1' && $r['selection']['status']['label'] === 'Correction demandée' && $r['selection']['note'] === 'Vérifier la disponibilité du 2e profil.')));

        $this->get('/matching/historique')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Matching/History')
            ->has('cycles', 10)
            ->where('cycles.0.reference', 'CYC-2026-040')
            ->where('cycles.0.status.label', 'Annulé')
            ->has('notifications', 8));

        $this->get('/matching/analyses')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Matching/Analyses')->where('success.rate', 86)->where('success.recommendations', 472));

        $response = $this->get('/matching/resultats/export');
        $response->assertOk()->assertDownload('matching-resultats-2026-09-23.csv');
        $this->assertStringContainsString('BES-2026-0147/1', $response->streamedContent());
    }

    public function test_launch_wizard_lists_eligible_offers(): void
    {
        $offer = $this->offer('BES-2026-0141/1');

        $this->get("/matching/lancer?portee=besoin&cible={$offer->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Matching/Launch')
            ->has('offers', 11)
            ->where('config.ok', true)
            ->where('config.sum', 100)
            ->where('prefill.cible', (string) $offer->id)
            ->where('result', null));
    }

    public function test_need_page_tabs(): void
    {
        $offer = $this->offer('BES-2026-0141/2');

        $this->get("/matching/besoins/{$offer->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Matching/Need')
            ->where('offer.status.label', 'À valider')
            ->where('offer.trade', 'Carreleur')
            ->where('selectionCount', 2)
            ->has('top', 4)
            ->where('header.crumbs.1.label', 'BES-2026-0141/2'));

        $this->get("/matching/besoins/{$offer->id}/resultats?tri=prox")->assertInertia(fn (Assert $page) => $page->where('results', fn ($rows): bool => collect($rows)->pluck('km')->sort()->values()->all() === collect($rows)->pluck('km')->all()));
        $this->get("/matching/besoins/{$offer->id}/exclus")->assertInertia(fn (Assert $page) => $page->where('excluded', fn ($rows): bool => count($rows) > 0 && collect($rows)->every(fn (array $r): bool => $r['blocked'])));
        $this->get("/matching/besoins/{$offer->id}/selection")->assertInertia(fn (Assert $page) => $page->has('selection.main', 2)->where('selection.status.label', 'En attente de validation'));

        $ids = collect((new MatchingEngine)->results($offer->fresh())['ok'])->take(2)->pluck('id')->implode(',');
        $this->get("/matching/besoins/{$offer->id}/comparaison?ids={$ids}")->assertInertia(fn (Assert $page) => $page->has('compare', 2));
        $this->get("/matching/besoins/{$offer->id}/comparaison")->assertInertia(fn (Assert $page) => $page->has('compare', 3));

        $this->get("/matching/besoins/{$offer->id}/inconnu")->assertNotFound();
    }

    public function test_match_page_tabs(): void
    {
        $offer = $this->offer('BES-2026-0141/1');
        $match = (new MatchingEngine)->results($offer)['ok'][0]['id'];

        foreach (['', '/score', '/besoin', '/profil', '/validation', '/affectation', '/resultat', '/historique'] as $tab) {
            $this->get("/matching/{$match}{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Matching/Match')->where('match.matchId', $match)->has('match.criteria', 8));
        }
        $this->get("/matching/{$match}")->assertInertia(fn (Assert $page) => $page->where('match.selected', true)->where('match.status.label', 'Sélectionné')->where('assignment', null));
        $this->get('/matching/mt-999999-0001')->assertNotFound();
    }
}
