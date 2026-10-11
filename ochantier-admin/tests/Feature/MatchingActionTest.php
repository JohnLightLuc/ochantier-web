<?php

namespace Tests\Feature;

use App\Models\MatchingCycle;
use App\Models\MatchingSelection;
use App\Models\NeedAssignment;
use App\Models\NeedOffer;
use App\Models\User;
use App\Support\MatchingEngine;
use Database\Seeders\MatchingSeeder;
use Database\Seeders\NeedSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, NeedSeeder::class, MatchingSeeder::class]);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function offer(string $reference): NeedOffer
    {
        return NeedOffer::where('reference', $reference)->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ok(NeedOffer $offer): array
    {
        return (new MatchingEngine)->results($offer->fresh())['ok'];
    }

    public function test_profiles_are_selected_within_the_number_of_positions(): void
    {
        $offer = $this->offer('BES-2026-0141/2');
        $selected = MatchingSelection::where('need_offer_id', $offer->id)->first()->main;
        $others = collect($this->ok($offer))->reject(fn (array $x): bool => in_array($x['p']['key'], $selected, true))->values();

        $this->post("/matching/offres/{$offer->id}/choix", ['key' => $others[0]['p']['key']])->assertSessionHasNoErrors();
        $this->assertCount(3, MatchingSelection::where('need_offer_id', $offer->id)->first()->main);
        $this->post("/matching/offres/{$offer->id}/choix", ['key' => $others[1]['p']['key']])->assertStatus(422);

        $this->post("/matching/offres/{$offer->id}/choix", ['key' => $others[0]['p']['key']]);
        $this->assertSame($selected, MatchingSelection::where('need_offer_id', $offer->id)->first()->main);

        $blocked = (new MatchingEngine)->results($offer->fresh())['ex'][0]['p']['key'];
        $this->post("/matching/offres/{$offer->id}/choix", ['key' => $blocked])->assertStatus(422);
        $this->post("/matching/offres/{$offer->id}/choix", ['key' => 'inconnu'])->assertNotFound();
    }

    public function test_replacements_submission_correction_and_rejection(): void
    {
        $offer = $this->offer('BES-2026-0141/2');
        $key = MatchingSelection::where('need_offer_id', $offer->id)->first()->main[0];

        $this->post("/matching/offres/{$offer->id}/remplacant", ['key' => $key, 'role' => 'Réserve'])->assertSessionHasNoErrors();
        $selection = MatchingSelection::where('need_offer_id', $offer->id)->first();
        $this->assertSame(['Réserve'], array_values($selection->replacements));
        $this->assertNotContains($key, $selection->main);

        $this->post("/matching/offres/{$offer->id}/soumission", ['status' => 'Brouillon'])->assertSessionHasNoErrors();
        $this->assertSame('Brouillon', $selection->fresh()->status);

        $this->post("/matching/offres/{$offer->id}/correction", ['reason' => 'Vérifier le tarif'])->assertSessionHasNoErrors();
        $this->assertSame('Correction demandée', $selection->fresh()->status);

        $this->post("/matching/offres/{$offer->id}/rejet", ['reason' => 'Profils inadaptés'])->assertSessionHasNoErrors();
        $this->assertSame([], $selection->fresh()->main);
        $this->assertDatabaseHas('matching_rejections', ['need_offer_id' => $offer->id, 'reason' => 'Sélection rejetée']);
        $this->post("/matching/offres/{$offer->id}/soumission", ['status' => 'En attente de validation'])->assertStatus(422);
    }

    public function test_selection_is_transmitted_to_assignments(): void
    {
        $offer = $this->offer('BES-2026-0142/1');
        $before = $offer->assignments()->count();
        $cycle = MatchingCycle::where('reference', $offer->last_cycle)->first();

        $this->post("/matching/offres/{$offer->id}/transmission")->assertSessionHasNoErrors();

        $this->assertSame($before + 4, $offer->assignments()->count());
        $assignment = $offer->assignments()->latest('id')->first();
        $this->assertSame('Matching', $assignment->source);
        $this->assertSame('to_contact', $assignment->status->value);
        $this->assertNotNull($assignment->score);
        $this->assertSame('Transmise aux Affectations', MatchingSelection::where('need_offer_id', $offer->id)->first()->status);
        $this->assertSame($cycle->assignments + 4, $cycle->fresh()->assignments);
        $this->assertSame('Couvert · validation des ouvriers', $offer->need->fresh()->load('lines', 'offers.line', 'offers.assignments', 'assignments')->status());

        $engine = new MatchingEngine;
        $this->assertFalse($engine->isEligible($engine->offer($offer->id)));
        $this->assertSame('Transmis aux Affectations', $engine->recommendationStatus($engine->offer($offer->id), $engine->item($engine->offer($offer->id), $assignment->worker_id ? "w{$assignment->worker_id}" : 'p-'.str($assignment->worker_name)->slug())));
        $this->post("/matching/offres/{$offer->id}/transmission")->assertStatus(422);
    }

    public function test_group_validation_and_overselection(): void
    {
        $first = $this->offer('BES-2026-0146/1');
        $second = $this->offer('BES-2026-0159/1');
        $this->post('/matching/transmission', ['offers' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, NeedAssignment::where('need_offer_id', $first->id)->where('source', 'Matching')->count());
        $this->assertSame(2, NeedAssignment::where('need_offer_id', $second->id)->where('source', 'Matching')->count());

        $offer = $this->offer('BES-2026-0141/1');
        $keys = collect($this->ok($offer))->take(3)->pluck('p.key')->all();
        MatchingSelection::create(['need_offer_id' => $offer->id, 'main' => $keys, 'replacements' => [], 'status' => 'Sélection en cours', 'author' => 'N. Yao', 'updated_on' => '2026-09-23']);
        $this->post("/matching/offres/{$offer->id}/sur-selection", ['mode' => 'derogation'])->assertSessionHasErrors('reason');
        $this->post("/matching/offres/{$offer->id}/sur-selection", ['mode' => 'remplacants'])->assertSessionHasNoErrors();
        $selection = MatchingSelection::where('need_offer_id', $offer->id)->first();
        $this->assertSame([$keys[0]], $selection->main);
        $this->assertSame(['Remplaçant principal', 'Réserve'], array_values($selection->replacements));
    }

    public function test_decisions_on_a_profile(): void
    {
        $offer = $this->offer('BES-2026-0141/1');
        $profile = $this->ok($offer)[0];
        $key = $profile['p']['key'];

        $this->post("/matching/offres/{$offer->id}/profils/{$key}/rejet", ['reason' => 'Coût trop élevé', 'nature' => 'Temporaire', 'comment' => 'Au-dessus du budget'])->assertSessionHasNoErrors();
        $engine = new MatchingEngine;
        $this->assertSame('Rejeté', $engine->recommendationStatus($engine->offer($offer->id), $engine->item($engine->offer($offer->id), $key)));

        $this->post("/matching/offres/{$offer->id}/profils/{$key}/recalcul", ['reason' => 'Coût'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('matching_recalculations', ['match_reference' => $profile['id'], 'old_score' => $profile['sc']['score'], 'reason' => 'Coût']);
        $this->assertSame('2026-09-23', $offer->fresh()->last_matched_on->format('Y-m-d'));

        $this->post("/matching/offres/{$offer->id}/profils/{$key}/derogation", ['motive' => 'Autre', 'justification' => 'Profil non bloqué'])->assertStatus(422);
        $blocked = collect((new MatchingEngine)->results($offer->fresh())['ex'])->first(fn (array $x): bool => ! collect($x['sc']['blocking'])->contains('absolute', true));
        $this->post("/matching/offres/{$offer->id}/profils/{$blocked['p']['key']}/derogation", ['motive' => 'Urgence chantier', 'justification' => 'Réception du lot imminente'])->assertSessionHasNoErrors();
        $this->assertFalse((new MatchingEngine)->item(NeedOffer::find($offer->id)->load('need.site.promoter', 'need.lines', 'line', 'assignments'), $blocked['p']['key'])['sc']['blocked']);

        $absolute = collect((new MatchingEngine)->results($offer->fresh())['ex'])->first(fn (array $x): bool => collect($x['sc']['blocking'])->contains('absolute', true));
        if ($absolute) {
            $this->post("/matching/offres/{$offer->id}/profils/{$absolute['p']['key']}/derogation", ['motive' => 'Autre', 'justification' => 'Tentative de contournement'])->assertStatus(422);
        }

        $this->post("/matching/offres/{$offer->id}/profils/{$key}/verification")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('matching_notifications', ['type' => 'Vérification demandée']);
        $this->post("/matching/offres/{$offer->id}/profils/{$key}/archivage")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('matching_rejections', ['need_offer_id' => $offer->id, 'candidate' => $key, 'reason' => 'Archivé']);
    }

    public function test_cycles_are_launched_and_cancelled(): void
    {
        $this->post('/matching/lancement', ['scope' => 'Un métier'])->assertSessionHasErrors('target');
        $this->post('/matching/lancement', ['scope' => 'Un métier', 'target' => 'Soudeur'])->assertSessionHasErrors('scope');

        $this->post('/matching/lancement', ['scope' => 'Un métier', 'target' => 'Électricien', 'duration' => 4])->assertRedirect('/matching/lancer?cycle=CYC-2026-041');
        $cycle = MatchingCycle::where('reference', 'CYC-2026-041')->firstOrFail();
        $this->assertSame(2, $cycle->needs);
        $this->assertSame('Électricien', $cycle->trade);
        $this->assertSame(16, $cycle->recommendations);
        $this->assertSame('CYC-2026-041', $this->offer('BES-2026-0142/1')->last_cycle);
        $this->assertDatabaseHas('matching_audits', ['action' => 'a lancé un cycle de Matching', 'subject' => 'CYC-2026-041']);

        $this->get('/matching/lancer?cycle=CYC-2026-041')->assertInertia(fn ($page) => $page->where('result.reference', 'CYC-2026-041')->where('result.status', 'Terminé'));

        $this->post('/matching/lancement/annulation', ['scope' => 'Tous les besoins éligibles'])->assertRedirect('/matching/lancer?cycle=CYC-2026-042');
        $this->assertSame('Annulé', MatchingCycle::where('reference', 'CYC-2026-042')->value('status'));

        $offer = $this->offer('BES-2026-0158/1');
        $this->post("/matching/offres/{$offer->id}/commerciaux")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('matching_notifications', ['type' => 'Besoin sans résultat', 'recipient' => 'Commerciaux Intérieur du pays']);
    }
}
