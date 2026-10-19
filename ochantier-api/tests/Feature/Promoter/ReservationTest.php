<?php

namespace Tests\Feature\Promoter;

use App\Enums\NeedStatus;
use App\Enums\ProposalStatus;
use App\Enums\SelectionStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WorkerReservation;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;

class ReservationTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->worker('Kouamé Koffi', 'macon', 22700, rating: 4.7);
        $this->worker('Touré Ali', 'macon', 23600, rating: 4.9);
        $this->worker('Bamba Seydou', 'macon', 22000, rating: 4.6);
        $this->worker('Serge Aka', 'peintre', 16400, rating: 4.7);
    }

    public function test_a_published_site_is_followed_as_a_reservation_in_search(): void
    {
        $site = $this->publishedSite();

        $this->getJson('/api/v1/promoter/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Recrutement · 3 ouvriers')
            ->assertJsonPath('data.0.site.title', 'Résidence Les Acacias')
            ->assertJsonPath('data.0.state', ['value' => 'recherche', 'label' => 'En recherche'])
            ->assertJsonPath('data.0.days', 10)
            ->assertJsonPath('data.0.total_amount', (2 * 25000 + 18000) * 10)
            ->assertJsonPath('data.0.total_is_estimate', true)
            ->assertJsonPath('data.0.deposit_amount', 204000)
            ->assertJsonPath('meta.counts', ['toutes' => 1, 'recherche' => 1, 'a_valider' => 0, 'confirmees' => 0, 'refusees' => 0])
            ->assertJsonPath('meta.sites.0.title', 'Résidence Les Acacias');

        $offer = Offer::query()->sole();
        $this->getJson("/api/v1/promoter/reservations/{$offer->id}")
            ->assertOk()
            ->assertJsonPath('data.header', 'Réservation en cours')
            ->assertJsonPath('data.steps.*.state', ['fait', 'en_cours', 'a_venir', 'a_venir'])
            ->assertJsonPath('data.team_title', 'Composition demandée')
            ->assertJsonPath('data.lines.*.trade.code', ['macon', 'peintre'])
            ->assertJsonPath('data.lines.0.count', 2)
            ->assertJsonPath('data.conditions.4', ['label' => 'Total estimé', 'value' => "680\u{00A0}000\u{00A0}FCFA"])
            ->assertJsonPath('data.can_accept', false);

        $this->assertSame('Recherche d’ouvriers en cours', $this->getJson("/api/v1/promoter/sites/{$site['id']}")->json('data.display_status.label'));
    }

    public function test_the_simulation_presents_a_team_to_accept_or_refuse(): void
    {
        $site = $this->publishedSite();

        $this->artisan('ochantier:simulate-matching')
            ->expectsOutputToContain('3/3 ouvriers')
            ->assertSuccessful();

        $offer = Offer::query()->sole();
        $proposal = Proposal::query()->sole();
        $daily = 25000 + 26000 + 18000;

        $this->getJson("/api/v1/promoter/reservations/{$offer->id}")
            ->assertJsonPath('data.state', ['value' => 'a_valider', 'label' => 'À valider'])
            ->assertJsonPath('data.header', 'Offre reçue')
            ->assertJsonPath('data.steps.*.state', ['fait', 'fait', 'en_cours', 'a_venir'])
            ->assertJsonPath('data.team_title', 'Équipe proposée')
            ->assertJsonPath('data.lines.0.workers.*.name', ['Touré Ali', 'Kouamé Koffi'])
            ->assertJsonPath('data.lines.0.workers.0.daily_rate', 26000)
            ->assertJsonPath('data.lines.1.workers.0.name', 'Serge Aka')
            ->assertJsonPath('data.daily_amount', $daily)
            ->assertJsonPath('data.total_amount', $daily * 10)
            ->assertJsonPath('data.total_is_estimate', false)
            ->assertJsonPath('data.deposit_amount', 207000)
            ->assertJsonPath('data.balance_amount', $daily * 10 - 207000)
            ->assertJsonPath('data.alert.title', "Offre reçue · acompte 207\u{00A0}000\u{00A0}FCFA")
            ->assertJsonPath('data.proposal.accept_label', 'Accepter et payer l’acompte')
            ->assertJsonPath('data.partial', null)
            ->assertJsonPath('data.can_accept', true);

        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.display_status', ['value' => 'propose', 'label' => 'Ouvriers trouvés'])
            ->assertJsonPath('data.pending_proposal.id', $proposal->id);

        $this->getJson('/api/v1/promoter/reservations?filter=a_valider')->assertJsonCount(1, 'data');
    }

    public function test_accepting_reserves_the_workers_until_the_deposit_is_paid(): void
    {
        $this->publishedSite();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();
        $proposal = Proposal::query()->sole();

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")
            ->assertOk()
            ->assertJsonPath('message', 'Proposition acceptée — réglez l’acompte pour confirmer la réservation.')
            ->assertJsonPath('data.state', ['value' => 'acompte', 'label' => 'Acompte à payer'])
            ->assertJsonPath('data.steps.*.state', ['fait', 'fait', 'fait', 'en_cours'])
            ->assertJsonPath('data.alert.title', "Acompte à payer : 207\u{00A0}000\u{00A0}FCFA")
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.can_pay_deposit', true);

        $this->assertSame(3, WorkerReservation::query()->where('status', 'active')->count());
        $this->assertSame(3, $proposal->offer->needs()->withCount('selections')->get()->sum('selections_count'));
        $this->assertSame(SelectionStatus::Reserve, $proposal->suggestions()->first()->need->selections()->first()->status);
        $this->assertSame(NeedStatus::PaiementEnAttente, Need::query()->whereBelongsTo($this->trade('macon'))->sole()->status);

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")
            ->assertJsonValidationErrors(['proposal' => 'Vous avez déjà accepté cette proposition.']);
    }

    public function test_a_partial_proposal_keeps_searching_for_the_missing_workers(): void
    {
        $this->publishedSite();
        $this->artisan('ochantier:simulate-matching', ['--missing' => 1])->assertSuccessful();
        $proposal = Proposal::query()->sole();
        $offer = $proposal->offer;

        $this->getJson("/api/v1/promoter/reservations/{$offer->id}")
            ->assertJsonPath('data.partial.label', '2/3 ouvriers trouvés')
            ->assertJsonPath('data.partial.hint', 'Acompte calculé sur 2 ouvriers · 1 encore recherché')
            ->assertJsonPath('data.missing.0', ['trade' => $this->trade('macon')->only(['id', 'code', 'label']), 'count' => 1, 'daily_rate' => 25000, 'status' => 'Recherche en cours'])
            ->assertJsonPath('data.proposal.accept_label', 'Accepter 2 ouvriers et payer');

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.missing.0.count', 1)
            ->assertJsonPath('data.workers_count', 2);

        $original = Need::query()->whereBelongsTo($this->trade('macon'))->whereNull('duplicated_from_need_id')->sole();
        $continued = Need::query()->where('duplicated_from_need_id', $original->id)->sole();
        $this->assertSame(1, $original->headcount);
        $this->assertSame(1, $continued->headcount);
        $this->assertSame(NeedStatus::Matching, $continued->status);
    }

    public function test_refusing_restarts_the_search_with_other_workers(): void
    {
        $site = $this->publishedSite();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();
        $proposal = Proposal::query()->sole();

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/refuse", ['reason_code' => 'inconnu'])
            ->assertJsonValidationErrors(['reason_code' => 'Motif inconnu.']);

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/refuse", ['reason_code' => 'tarif_eleve', 'comment' => 'Budget serré.'])
            ->assertOk()
            ->assertJsonPath('message', 'Proposition refusée — nous cherchons une autre équipe.')
            ->assertJsonPath('data.state.value', 'recherche')
            ->assertJsonPath('data.last_refusal.reason', ['code' => 'tarif_eleve', 'label' => 'Tarif trop élevé'])
            ->assertJsonPath('data.history.0.status.value', 'refusee')
            ->assertJsonPath('data.history.0.refusal_comment', 'Budget serré.');

        $this->assertSame(3, Suggestion::query()->where('status', SuggestionStatus::Ecarte)->count());
        $this->assertSame(SiteStatus::Recherche, Site::query()->find($site['id'])->status);
        $this->getJson('/api/v1/promoter/reservations?filter=refusees')->assertJsonCount(1, 'data');

        $this->artisan('ochantier:simulate-matching')->expectsOutputToContain('1/3 ouvriers')->assertSuccessful();
        $this->assertSame(['Seydou'], Proposal::query()->where('sequence', 2)->sole()->suggestions()->with('worker')->get()->pluck('worker.last_name')->all());
    }

    public function test_a_worker_reserved_elsewhere_cannot_be_accepted_twice(): void
    {
        $first = $this->publishedSite(['macon' => [1, 25000]]);
        $second = $this->publishedSite(['macon' => [1, 25000]], 'Villa Riviera');
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();

        [$a, $b] = Proposal::query()->orderBy('published_at')->get()->all();
        $this->assertSame($a->suggestions()->value('worker_id'), $b->suggestions()->value('worker_id'));

        $this->postJson("/api/v1/promoter/proposals/{$a->id}/accept")->assertOk();
        $this->postJson("/api/v1/promoter/proposals/{$b->id}/accept")
            ->assertJsonValidationErrors(['proposal' => 'Touré Ali vient d’être réservé sur un autre chantier : une nouvelle proposition vous sera faite.']);

        $this->assertSame(ProposalStatus::AValider, $b->fresh()->status);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_an_expired_proposal_can_no_longer_be_decided(): void
    {
        $this->publishedSite();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();
        $proposal = Proposal::query()->sole();
        $proposal->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")
            ->assertJsonValidationErrors(['proposal' => 'Cette proposition n’est plus valable : une nouvelle équipe vous sera proposée.']);
    }

    public function test_reservations_of_other_promoters_are_not_found(): void
    {
        $this->publishedSite();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();
        $offer = Offer::query()->sole();
        $proposal = Proposal::query()->sole();

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->getJson("/api/v1/promoter/reservations/{$offer->id}")->assertNotFound();
        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")->assertNotFound();
        $this->getJson('/api/v1/promoter/reservations')->assertJsonCount(0, 'data');
    }

    public function test_a_new_reservation_is_added_to_a_published_site(): void
    {
        $site = $this->publishedSite();
        $draft = $this->postJson('/api/v1/promoter/sites', ['title' => 'Duplex Bingerville'])->json('data');

        $this->postJson("/api/v1/promoter/sites/{$draft['id']}/reservations", ['needs' => [['trade_id' => $this->trade('peintre')->id, 'headcount' => 1, 'daily_rate' => 18000]]])
            ->assertJsonValidationErrors(['site' => 'Ce chantier est un brouillon : ajoutez les besoins dans le chantier puis publiez-le.']);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/reservations", ['needs' => [['trade_id' => $this->trade('peintre')->id, 'headcount' => 2]]])
            ->assertJsonValidationErrors(['reservation' => 'Proposez un tarif pour « Peintre »']);

        $start = now()->addDays(20)->toDateString();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/reservations", [
            'needs' => [['trade_id' => $this->trade('peintre')->id, 'headcount' => 2, 'daily_rate' => 18000]],
            'start_date' => $start,
            'end_date' => now()->addDays(23)->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Recherche publiée pour « Résidence Les Acacias »')
            ->assertJsonPath('data.start_date', $start)
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.title', 'Recrutement · 2 ouvriers')
            ->assertJsonPath('data.state.value', 'recherche');

        $this->getJson("/api/v1/promoter/reservations?site_id={$site['id']}")->assertJsonCount(2, 'data');
        $this->assertSame(4, Need::query()->where('trade_id', $this->trade('peintre')->id)->where('start_date', $start)->sole()->duration_days);
    }

    /**
     * Chantier publié avec ses besoins : métier => [effectif, tarif proposé].
     *
     * @param  array<string, array{0: int, 1: int}>  $needs
     * @return array<string, mixed>
     */
    private function publishedSite(array $needs = ['macon' => [2, 25000], 'peintre' => [1, 18000]], string $title = 'Résidence Les Acacias'): array
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => $title])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => 'Angré, Abidjan',
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(14)->toDateString(),
        ])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", [
            'type' => 'journaliere',
            'needs' => collect($needs)->map(fn (array $need, string $trade) => ['trade_id' => $this->trade($trade)->id, 'headcount' => $need[0], 'daily_rate' => $need[1]])->values()->all(),
        ])->assertOk();

        return $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk()->json('data');
    }
}
