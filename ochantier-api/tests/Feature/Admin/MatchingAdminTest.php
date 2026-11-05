<?php

namespace Tests\Feature\Admin;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\MatchingDerogation;
use App\Models\MatchingExclusion;
use App\Models\MatchingWeightSet;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Suggestion;
use App\Models\Worker;
use App\Models\WorkerResponse;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Matching : file des besoins, moteur (exclusions, score, classement) et relance.
 */
class MatchingAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * Offre publiée, sans proposition. La pièce d’identité n’est pas exigée, sauf dans le test qui la vérifie.
     */
    private function openOffer(bool $requireIdentity = false): Offer
    {
        $this->publishedSite();

        if (! $requireIdentity) {
            $weights = MatchingWeightSet::query()->where('is_active', true)->firstOrFail();
            $weights->forceFill(['exclusion_rules' => [...$weights->exclusion_rules, 'identite_verifiee' => false]])->save();
        }

        return $this->site->offers()->firstOrFail();
    }

    private function url(Offer $offer, string $suffix = ''): string
    {
        return "/api/v1/admin/matching/offers/{$offer->id}{$suffix}";
    }

    public function test_the_queue_lists_the_open_offers(): void
    {
        $offer = $this->openOffer();

        $this->asAdmin()->getJson('/api/v1/admin/matching/queue')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $offer->id)
            ->assertJsonPath('data.0.last_run_at', null);
    }

    public function test_a_run_ranks_the_workers_and_explains_the_score(): void
    {
        $offer = $this->openOffer();

        $response = $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();

        $need = $response->json('data.needs.0');
        $this->assertNotEmpty($need['suggestions']);
        $first = $need['suggestions'][0];
        $this->assertSame(1, $first['rank']);
        $this->assertSame('suggere', $first['status']['value']);
        $this->assertCount(8, $first['criteria']);
        $this->assertEqualsWithDelta($first['score'], collect($first['criteria'])->sum('contribution'), 0.1);
        $this->assertSame(100, collect($first['criteria'])->sum('weight'));
        $this->assertGreaterThanOrEqual(collect($need['suggestions'])->last()['score'], $first['score']);

        $this->assertSame(OfferStatus::Soumise, $offer->fresh()->status);
        $this->assertSame(NeedStatus::Matching, $offer->needs()->first()->status);
        $this->assertSame(SiteStatus::Recherche, $this->site->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'matching.run')->exists());
        $this->getJson('/api/v1/admin/matching/queue')->assertJsonPath('data.0.id', $offer->id);
    }

    public function test_an_expensive_or_distant_worker_is_excluded_with_a_reason(): void
    {
        $offer = $this->openOffer();
        $need = $offer->needs()->firstOrFail();
        $need->update(['indicative_daily_budget' => 10000]);
        $worker = Worker::query()->where('first_name', 'Kouamé')->firstOrFail();
        $worker->update(['estimated_daily_rate' => 50000]);

        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();

        $exclusion = MatchingExclusion::query()->where('worker_id', $worker->id)->sole();
        $this->assertSame('cout', $exclusion->criterion);
        $this->assertStringContainsString('FCFA/j au plus', (string) $exclusion->comment);
        $this->assertSame('operationnelle', $exclusion->nature->value);
        $this->assertFalse(Suggestion::query()->where('worker_id', $worker->id)->where('status', 'suggere')->exists());
    }

    public function test_a_suspended_or_busy_worker_is_excluded_absolutely(): void
    {
        $offer = $this->openOffer();
        $worker = Worker::query()->where('first_name', 'Touré')->firstOrFail();
        $worker->update(['verification_status' => 'suspendu']);

        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();

        $this->assertSame('reglementaire', MatchingExclusion::query()->where('worker_id', $worker->id)->sole()->nature->value);
    }

    public function test_a_worker_without_a_verified_identity_is_excluded(): void
    {
        $offer = $this->openOffer(requireIdentity: true);

        $response = $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();

        $this->assertSame([], $response->json('data.needs.0.suggestions'));
        $this->assertSame('identite', $response->json('data.needs.0.exclusions.0.criterion'));
        $this->assertSame('Opérationnelle', $response->json('data.needs.0.exclusions.0.nature.label'));
    }

    public function test_a_second_run_keeps_the_contacted_workers(): void
    {
        $offer = $this->openOffer();
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        $kept = Suggestion::query()->where('status', SuggestionStatus::Suggere)->orderBy('rank')->firstOrFail();
        $kept->update(['status' => SuggestionStatus::Contacte]);

        $response = $this->postJson($this->url($offer, '/run'))->assertOk();

        $this->assertSame(SuggestionStatus::Contacte, $kept->fresh()->status);
        $this->assertSame(1, collect($response->json('data.needs.0.suggestions'))->where('worker.id', $kept->worker_id)->count());
        $this->assertSame('relance', $response->json('data.needs.0.last_run.type'));
    }

    public function test_a_closed_offer_cannot_be_matched(): void
    {
        $offer = $this->openOffer();
        $offer->forceFill(['status' => OfferStatus::Suspendue])->save();

        $this->asAdmin()->getJson($this->url($offer))->assertOk()->assertJsonPath('data.run_refusal', 'Ce besoin n’est pas ouvert à la recherche (suspendue).');
        $this->postJson($this->url($offer, '/run'))->assertJsonValidationErrors(['offer']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestions(Offer $offer): array
    {
        return collect($this->getJson($this->url($offer))->json('data.needs'))->flatMap(fn (array $need) => $need['suggestions'])->all();
    }

    private function agree(string $suggestion): void
    {
        $this->postJson("/api/v1/admin/matching/suggestions/{$suggestion}/response", ['channel' => 'appel', 'decision' => 'accepte'])->assertOk();
    }

    public function test_a_response_is_recorded_with_its_rate_and_author(): void
    {
        $offer = $this->openOffer();
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        [$first, $second] = $this->suggestions($offer);

        $this->postJson("/api/v1/admin/matching/suggestions/{$first['id']}/contact", ['channel' => 'appel'])->assertOk();
        $this->assertSame(NeedStatus::ContactOuvriers, Suggestion::query()->findOrFail($first['id'])->need->fresh()->status);

        $response = $this->postJson("/api/v1/admin/matching/suggestions/{$first['id']}/response", ['channel' => 'appel', 'decision' => 'refuse', 'conditions' => 'Déjà pris ce mois-ci.'])->assertOk();
        $row = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['suggestions'])->firstWhere('id', $first['id']);
        $this->assertSame('refuse', $row['status']['value']);
        $this->assertSame('Déjà pris ce mois-ci.', $row['response']['conditions']);
        $this->assertSame($first['worker_daily_rate'], $row['response']['net_daily_rate']);
        $this->assertSame('Appel', $row['contact']['channel']);
        $this->assertNotNull($row['worker']['phone']);

        $stored = WorkerResponse::query()->where('suggestion_id', $first['id'])->sole();
        $this->assertSame($first['worker_daily_rate'], $stored->net_daily_rate_shown);

        // Un ouvrier qui a refusé ce besoin ne revient pas à la relance.
        $this->postJson($this->url($offer, '/run'))->assertOk();
        $this->assertTrue(MatchingExclusion::query()->where('worker_id', $first['worker']['id'])->where('criterion', 'refus')->exists());

        $this->postJson('/api/v1/admin/matching/suggestions/'.$second['id'].'/response', ['channel' => 'inconnu', 'decision' => 'accepte'])->assertJsonValidationErrors(['channel']);
    }

    public function test_the_real_flow_goes_from_the_run_to_the_deposit(): void
    {
        $offer = $this->openOffer();
        $promoter = $this->promoter;
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();

        $this->postJson($this->url($offer, '/send'))->assertJsonValidationErrors(['offer']);

        foreach ($this->suggestions($offer) as $suggestion) {
            $this->agree($suggestion['id']);
        }

        $this->getJson($this->url($offer))->assertJsonPath('data.retained', 3);
        $this->postJson($this->url($offer, '/send'))->assertOk()->assertJsonPath('data.offer.status.value', 'publiee');

        $proposal = Proposal::query()->sole();
        $this->assertSame(ProposalStatus::AValider, $proposal->status);
        $this->assertSame(3, $proposal->proposed_count);
        $this->assertFalse($proposal->is_partial);
        $this->assertNotNull($proposal->published_by_admin_id);
        $this->assertSame(OfferStatus::Publiee, $offer->fresh()->status);
        $this->assertSame(SiteStatus::Paiement, $this->site->fresh()->status);
        $this->assertSame(3, Suggestion::query()->where('status', SuggestionStatus::ProposePromoteur)->count());
        $this->assertTrue(Notification::query()->where('event_code', 'proposal.received')->where('recipient_id', $promoter->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'matching.proposal_sent')->exists());
        $this->postJson($this->url($offer, '/run'))->assertJsonValidationErrors(['offer']);

        $this->actingAsPromoter($promoter);
        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['proposal_id' => $proposal->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertCreated();

        $this->assertSame(SiteStatus::Confirme, $this->site->fresh()->status);
        $this->assertSame(3, Assignment::query()->count());
    }

    public function test_a_partial_proposal_keeps_the_reserves_and_the_search_open(): void
    {
        $offer = $this->openOffer();
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        $suggestions = $this->suggestions($offer);
        $this->agree($suggestions[0]['id']);

        $this->postJson($this->url($offer, '/send'))->assertOk();

        $proposal = Proposal::query()->sole();
        $this->assertTrue($proposal->is_partial);
        $this->assertSame(1, $proposal->proposed_count);
    }

    public function test_an_expired_proposal_reopens_the_search(): void
    {
        $offer = $this->openOffer();
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        $this->agree($this->suggestions($offer)[0]['id']);
        $this->postJson($this->url($offer, '/send'))->assertOk();
        $proposal = Proposal::query()->sole();
        $proposal->forceFill(['expires_at' => now()->subHour()])->save();

        $this->postJson($this->url($offer, '/run'))->assertOk();

        $this->assertSame(ProposalStatus::Expiree, $proposal->fresh()->status);
        $this->assertSame(SiteStatus::Recherche, $this->site->fresh()->status);
    }

    public function test_a_worker_can_be_set_aside_then_brought_back(): void
    {
        $offer = $this->openOffer();
        $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        $first = $this->suggestions($offer)[0];

        $response = $this->postJson("/api/v1/admin/matching/suggestions/{$first['id']}/exclusion", ['reason' => 'Mauvais retour du dernier chantier.'])->assertOk();
        $exclusion = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['exclusions'])->firstWhere('worker.id', $first['worker']['id']);
        $this->assertSame('manuelle', $exclusion['nature']['value']);
        $this->assertTrue($exclusion['can_lift']);
        $this->assertFalse($exclusion['can_request_derogation']);

        $this->postJson($this->url($offer, '/run'))->assertOk();
        $this->assertNull(collect($this->suggestions($offer))->firstWhere('worker.id', $first['worker']['id']));

        $this->deleteJson("/api/v1/admin/matching/exclusions/{$exclusion['id']}")->assertOk();
        $this->postJson($this->url($offer, '/run'))->assertOk();
        $this->assertNotNull(collect($this->suggestions($offer))->where('status.value', 'suggere')->firstWhere('worker.id', $first['worker']['id']));
    }

    public function test_a_derogation_needs_a_second_person_and_takes_effect_at_the_next_run(): void
    {
        $offer = $this->openOffer();
        $worker = Worker::query()->where('first_name', 'Kouamé')->firstOrFail();
        $worker->update(['estimated_daily_rate' => 50000]);
        $requester = Admin::factory()->role('operations')->create();
        $approver = Admin::factory()->role('direction')->create();

        $response = $this->asAdmin($requester)->postJson($this->url($offer, '/run'))->assertOk();
        $exclusion = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['exclusions'])->firstWhere('worker.id', $worker->id);
        $this->assertTrue($exclusion['can_request_derogation']);

        $this->postJson("/api/v1/admin/matching/exclusions/{$exclusion['id']}/derogation", ['justification' => 'court'])->assertJsonValidationErrors(['justification']);
        $this->postJson("/api/v1/admin/matching/exclusions/{$exclusion['id']}/derogation", ['justification' => 'Seul maçon disponible à Cocody ce mois-ci.'])->assertOk();
        $this->postJson("/api/v1/admin/matching/exclusions/{$exclusion['id']}/derogation", ['justification' => 'Seul maçon disponible à Cocody ce mois-ci.'])->assertJsonValidationErrors(['offer']);

        $derogation = MatchingDerogation::query()->sole();
        $this->postJson("/api/v1/admin/matching/derogations/{$derogation->id}/approve")->assertForbidden();

        $this->postJson($this->url($offer, '/run'))->assertOk();
        $this->assertNull(collect($this->suggestions($offer))->firstWhere('worker.id', $worker->id));

        $this->asAdmin($approver)->getJson('/api/v1/admin/matching/derogations')->assertOk()->assertJsonPath('data.0.can_decide', true)->assertJsonPath('data.0.worker', 'Kouamé Koffi');
        $this->postJson("/api/v1/admin/matching/derogations/{$derogation->id}/approve")->assertOk();
        $this->assertSame($approver->id, $derogation->fresh()->approved_by_admin_id);

        $this->asAdmin($requester)->postJson($this->url($offer, '/run'))->assertOk();
        $row = collect($this->suggestions($offer))->firstWhere('worker.id', $worker->id);
        $this->assertStringContainsString('Dérogation accordée', implode(' ', $row['warnings']));

        $this->asAdmin($approver)->deleteJson("/api/v1/admin/matching/derogations/{$derogation->id}")->assertOk();
        $this->asAdmin($requester)->postJson($this->url($offer, '/run'))->assertOk();
        $this->assertNull(collect($this->suggestions($offer))->where('status.value', 'suggere')->firstWhere('worker.id', $worker->id));
    }

    public function test_the_author_cannot_approve_their_own_derogation(): void
    {
        $offer = $this->openOffer();
        Worker::query()->where('first_name', 'Kouamé')->firstOrFail()->update(['estimated_daily_rate' => 50000]);
        $author = Admin::factory()->role('super_admin')->create();

        $response = $this->asAdmin($author)->postJson($this->url($offer, '/run'))->assertOk();
        $exclusion = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['exclusions'])->first();
        $this->postJson("/api/v1/admin/matching/exclusions/{$exclusion['id']}/derogation", ['justification' => 'Justification assez longue.'])->assertOk();
        $derogation = MatchingDerogation::query()->sole();

        $this->getJson('/api/v1/admin/matching/derogations')->assertJsonPath('data.0.can_decide', false);
        $this->postJson("/api/v1/admin/matching/derogations/{$derogation->id}/approve")->assertJsonValidationErrors(['offer']);
    }

    public function test_a_regulatory_exclusion_refuses_any_derogation(): void
    {
        $offer = $this->openOffer();
        Worker::query()->where('first_name', 'Kouamé')->firstOrFail()->update(['estimated_daily_rate' => 50000, 'verification_status' => 'suspendu']);

        $response = $this->asAdmin()->postJson($this->url($offer, '/run'))->assertOk();
        $rows = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['exclusions']);
        $this->assertTrue($rows->every(fn (array $row) => $row['can_request_derogation'] === false));

        $this->postJson("/api/v1/admin/matching/exclusions/{$rows->firstWhere('criterion', 'cout')['id']}/derogation", ['justification' => 'Justification assez longue.'])->assertJsonValidationErrors(['offer']);
        $this->postJson("/api/v1/admin/matching/exclusions/{$rows->firstWhere('criterion', 'statut')['id']}/derogation", ['justification' => 'Justification assez longue.'])->assertJsonValidationErrors(['offer']);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $offer = $this->openOffer();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/matching/queue')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create())->postJson($this->url($offer, '/run'))->assertForbidden();
        $this->postJson($this->url($offer, '/send'))->assertForbidden();
    }
}
