<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Réclamations branchées sur l’API (api/v1/admin/claims).
 */
class ClaimTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const CLAIM = '01a16ab6-0000-7000-8000-0000000000b1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Support', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['disputes.read', 'disputes.update']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        return [
            'id' => self::CLAIM,
            'reference' => 'REC-2026-000001',
            'category' => ['value' => 'paiement', 'label' => 'Paiement'],
            'subject' => 'Échéance payée deux fois',
            'claimant' => ['type' => 'worker', 'type_label' => 'Ouvrier', 'id' => 'w1', 'name' => 'Touré Ali'],
            'site' => ['id' => 's1', 'title' => 'Résidence Les Palmiers'],
            'priority' => ['value' => 'haute', 'label' => 'Haute'],
            'status' => ['value' => 'ouverte', 'label' => 'Ouverte'],
            'owner' => null,
            'due_at' => '2026-11-08T10:00:00+00:00',
            'overdue' => true,
            'created_at' => '2026-11-06T10:00:00+00:00',
        ];
    }

    public function test_the_list_translates_filters_and_shows_counts(): void
    {
        Http::fake([self::API.'/claims?*' => Http::response(['data' => [$this->claim()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'categories' => [['value' => 'paiement', 'label' => 'Paiement']], 'counts' => ['overdue' => 1, 'statuses' => ['ouverte' => 1, 'en_traitement' => 0, 'resolue' => 0]]]])]);

        $this->get('/avis-litiges/reclamations?categorie=paiement&retard=1&demandeur=worker')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Claims')
                ->where('kpis.overdue', 1)
                ->where('claims.data.0.priority.tone', 'o')
                ->where('can.update', true));

        Http::assertSent(fn (ClientRequest $request) => $request['category'] === 'paiement' && $request['overdue'] === '1' && $request['claimant_type'] === 'worker');
    }

    public function test_the_detail_page_shows_the_journal_and_actions(): void
    {
        Http::fake([self::API.'/claims/'.self::CLAIM => Http::response(['data' => [
            ...$this->claim(),
            'description' => 'Débité deux fois.',
            'other_party' => null,
            'resolution' => null,
            'history' => [['id' => '1', 'action' => 'claims.created', 'reason' => null, 'by' => 'Awa Support', 'date' => '2026-11-06T10:00:00+00:00']],
            'can' => ['take' => true, 'priority' => true, 'resolve' => true, 'reject' => true, 'close' => false],
        ]])]);

        $this->get('/avis-litiges/reclamations/'.self::CLAIM)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Claim')
                ->where('claim.history.0.label', 'Saisie au Back Office')
                ->where('claim.actions.take', true));
    }

    public function test_filing_keeps_field_errors_on_the_form(): void
    {
        Http::fake([self::API.'/claims' => Http::response(['message' => 'Numéro inconnu.', 'errors' => ['phone_number' => ['Aucun ouvrier avec ce numéro.']]], 422)]);

        $this->from('/avis-litiges/reclamations')
            ->post('/avis-litiges/reclamations', ['claimant_type' => 'worker', 'dial_code' => '225', 'phone_number' => '0700000000', 'category' => 'paiement', 'priority' => 'normale', 'subject' => 'Semaine manquante', 'description' => 'Une semaine n’est pas reversée.'])
            ->assertRedirect('/avis-litiges/reclamations')
            ->assertSessionHasErrors('phone_number');
    }

    public function test_actions_reach_the_api_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/claims/'.self::CLAIM.'/resolve' => Http::response(['message' => 'Réclamation résolue.', 'data' => []]),
            self::API.'/claims/'.self::CLAIM.'/close' => Http::response(['message' => 'Refusé.', 'errors' => ['claim' => ['Cette réclamation n’est plus à traiter.']]], 422),
        ]);
        $base = '/avis-litiges/reclamations/'.self::CLAIM;

        $this->from($base)->post("{$base}/resolution", ['reason' => 'Doublon remboursé.'])->assertRedirect($base);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/resolve') && $request['reason'] === 'Doublon remboursé.');

        $this->from($base)->post("{$base}/cloture")->assertRedirect($base)->assertSessionHasNoErrors();
    }

    public function test_old_links_return_to_the_list(): void
    {
        $this->get('/avis-litiges/reclamations/REC-2026-0301')->assertRedirect('/avis-litiges/reclamations');
    }
}
