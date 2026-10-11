<?php

namespace Tests\Feature;

use App\Enums\NeedAssignmentStatus;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\PromoterOffer;
use App\Models\User;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterOfferSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssignmentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class, PromoterOfferSeeder::class]);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    public function test_list_shows_tracked_needs_and_promoter_offers(): void
    {
        $this->get('/affectations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assignments/Index')
                ->has('kpis', 4)
                ->has('needs', 9)
                ->where('needs.0.reference', 'BES-2026-0148')
                ->where('needs.0.status.label', Need::COVERED)
                ->has('offers', 8)
                ->where('offers.7.status.label', PromoterOffer::DONE)
                ->where('filters.besoins', 'Tous')
                ->where('filters.offres', 'Toutes')
                ->has('openPosts')
            );
    }

    public function test_dashboard_filter_labels_are_mapped_to_the_lists(): void
    {
        $this->get('/affectations?filtre=Remplacements')->assertInertia(fn (Assert $page) => $page->where('filters.besoins', 'Tous')->where('filters.offres', PromoterOffer::REPLACEMENT));
        $this->get('/affectations?filtre=Confirmées&q=BES-2026-0141')->assertInertia(fn (Assert $page) => $page->where('filters.besoins', 'Comblés')->where('filters.q', 'BES-2026-0141'));
        $this->get('/affectations?filtre=inconnu')->assertInertia(fn (Assert $page) => $page->where('filters.besoins', 'Tous'));
    }

    public function test_export_lists_needs_and_offers(): void
    {
        $csv = $this->get('/affectations/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('BES-2026-0148', $csv);
        $this->assertStringContainsString('OFP-2026-051', $csv);
    }

    public function test_need_page_shows_workers_proposals_missing_posts_and_schedule(): void
    {
        $this->get('/affectations/besoins/bes-2026-0141')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assignments/Need')
                ->where('need.reference', 'BES-2026-0141')
                ->where('need.open', true)
                ->has('assignments', 5)
                ->where('assignments.0.state', 'to_contact')
                ->where('coverage.rest', 4)
                ->has('proposals.rows')
                ->has('missing', 2)
                ->where('missing.0.trade', 'Maçon')
                ->where('schedule.mode', 'Hebdomadaire')
                ->where('schedule.entries.0.status', 'En attente')
                ->has('progress', 6)
                ->has('manualPosts', 2)
                ->where('header.crumbs.1.label', 'BES-2026-0141')
            );

        $this->get('/affectations/besoins/inconnu')->assertNotFound();
    }

    public function test_refused_worker_gets_replacement_suggestions(): void
    {
        $refused = NeedAssignment::query()->where('status', NeedAssignmentStatus::Refused)->firstOrFail();
        $need = Need::findOrFail($refused->need_id);

        $this->get("/affectations/besoins/{$need->slug}?remplacement={$refused->reference}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('replacement.assignment.reference', $refused->reference)
                ->has('replacement.rows')
            );
        $this->get("/affectations/besoins/{$need->slug}?remplacement=0")->assertInertia(fn (Assert $page) => $page->where('replacement', null));
    }

    public function test_promoter_offer_page_shows_profiles_and_replacements(): void
    {
        $this->get('/affectations/offres/ofp-2026-052')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assignments/Offer')
                ->where('offer.statusLabel', PromoterOffer::TO_PROCESS)
                ->has('profiles', 3)
                ->where('counts.refused', 1)
                ->where('replacement.profile.name', fn (string $name): bool => $name !== '')
                ->has('replacement.rows', 3)
                ->where('deposit.rate', 30)
                ->has('activities', 1)
            );

        $this->get('/affectations/offres/ofp-2026-054')->assertInertia(fn (Assert $page) => $page->where('offer.statusLabel', PromoterOffer::DONE)->where('offer.paymentReference', 'Mobile Money · MM-58214'));
        $this->get('/affectations/offres/inconnue')->assertNotFound();
    }

    public function test_matching_proposals_manual_add_and_replacement_create_workers_to_contact(): void
    {
        $base = '/affectations/besoins/bes-2026-0141';
        $page = $this->get($base);
        $proposal = $page->inertiaProps('proposals.rows.0');
        $this->assertNotNull($proposal, 'Le besoin doit avoir au moins une proposition automatique.');

        $this->post("{$base}/propositions", ['picks' => ["{$proposal['offerId']}:{$proposal['key']}"]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('need_assignments', ['worker_name' => $proposal['name'], 'status' => NeedAssignmentStatus::ToContact->value, 'source' => 'Matching']);
        $this->assertDatabaseHas('matching_audits', ['action' => 'a accepté des propositions automatiques du Matching', 'subject' => 'BES-2026-0141']);

        $post = $this->get($base)->inertiaProps('manualPosts.0');
        $this->post("{$base}/ouvriers", ['offer' => $post['offerId'], 'key' => $post['options'][0]['value']])->assertRedirect($base);
        $this->assertDatabaseHas('need_assignments', ['need_offer_id' => $post['offerId'], 'source' => 'Manuel']);

        $need = Need::query()->where('slug', 'bes-2026-0141')->firstOrFail();
        $refused = $need->assignments()->where('status', NeedAssignmentStatus::Contacted)->firstOrFail();
        $this->post("{$base}/ouvriers/{$refused->id}/invalidation", ['motif' => 'Injoignable', 'reason' => 'Trois appels sans réponse'])->assertRedirect("{$base}?remplacement={$refused->reference}");
        $candidate = $this->get("{$base}?remplacement={$refused->reference}")->inertiaProps('replacement.rows.0');
        $this->assertNotNull($candidate, 'Des remplaçants doivent être proposés pour un poste ouvert.');
        $this->post("{$base}/ouvriers/{$refused->id}/remplacant", ['key' => $candidate['key']])->assertRedirect($base);
        $this->assertSame(NeedAssignmentStatus::Replaced, $refused->fresh()->status);
        $this->assertSame($candidate['name'], $refused->fresh()->replaced_by);
        $this->assertDatabaseHas('need_assignments', ['need_id' => $need->id, 'worker_name' => $candidate['name'], 'replaces' => $refused->worker_name]);
    }
}
