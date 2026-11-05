<?php

namespace Tests\Feature\Admin;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\MatchingExclusion;
use App\Models\MatchingWeightSet;
use App\Models\Offer;
use App\Models\Suggestion;
use App\Models\Worker;
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

    public function test_the_routes_need_their_permissions(): void
    {
        $offer = $this->openOffer();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/matching/queue')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create())->postJson($this->url($offer, '/run'))->assertForbidden();
    }
}
