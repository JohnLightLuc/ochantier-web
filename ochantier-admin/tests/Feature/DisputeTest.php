<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Litiges et vue d’ensemble branchés sur l’API (api/v1/admin/disputes).
 */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const DISPUTE = '01a16ab6-0000-7000-8000-0000000000c1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Support', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['ratings.read', 'disputes.read', 'disputes.update', 'disputes.validate']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispute(): array
    {
        return [
            'id' => self::DISPUTE,
            'reference' => 'LIT-2026-000001',
            'category' => ['value' => 'prestation', 'label' => 'Qualité de la prestation'],
            'parties' => [['type' => 'promoter_profile', 'type_label' => 'Promoteur', 'id' => 'p1', 'name' => 'Yao Koffi', 'role' => ['value' => 'demandeur', 'label' => 'Demandeur']]],
            'site' => ['id' => 's1', 'title' => 'Résidence Les Palmiers'],
            'mission' => ['id' => 'm1', 'reference' => 'MIS-1'],
            'urgency' => ['value' => 'haute', 'label' => 'Haute'],
            'status' => ['value' => 'en_instruction', 'label' => 'En instruction'],
            'owner' => 'Awa Support',
            'appeal_of' => null,
            'created_at' => '2026-11-06T10:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'categories' => [['value' => 'prestation', 'label' => 'Qualité de la prestation']], 'counts' => ['unassigned' => 0, 'refunds_pending' => 1, 'overdue' => 2, 'published' => 3, 'hidden' => 0, 'reported' => 1, 'suspected' => 0, 'average' => 4.2, 'statuses' => ['ouvert' => 1, 'en_instruction' => 2, 'resolu' => 0, 'en_recours' => 1, 'clos' => 0, 'ouverte' => 1, 'en_traitement' => 1]]];
    }

    public function test_the_overview_gathers_the_three_queues(): void
    {
        Http::fake([
            self::API.'/ratings?*' => Http::response(['data' => [], 'meta' => $this->meta()]),
            self::API.'/claims?*' => Http::response(['data' => [], 'meta' => $this->meta()]),
            self::API.'/disputes?*' => Http::response(['data' => [$this->dispute()], 'meta' => $this->meta()]),
        ]);

        $this->get('/avis-litiges')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Overview')
                ->where('ratings.reported', 1)
                ->where('claims.open', 2)
                ->where('claims.overdue', 2)
                ->where('disputes.open', 3)
                ->where('disputes.refundsPending', 1)
                ->where('disputes.latest.0.urgency.tone', 'o'));
    }

    public function test_the_list_translates_filters(): void
    {
        Http::fake([self::API.'/disputes?*' => Http::response(['data' => [$this->dispute()], 'meta' => $this->meta()])]);

        $this->get('/avis-litiges/litiges?statut=en_recours&urgence=haute&sans_responsable=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quality/Disputes')->where('kpis.appeals', 1)->where('can.decide', true)->where('can.approve', false));

        Http::assertSent(fn (ClientRequest $request) => $request['status'] === 'en_recours' && $request['urgency'] === 'haute' && $request['unassigned'] === '1');
    }

    public function test_the_detail_page_shows_the_decision_and_the_refund(): void
    {
        Http::fake([self::API.'/disputes/'.self::DISPUTE => Http::response(['data' => [
            ...$this->dispute(),
            'description' => 'Mur à reprendre.',
            'claim' => null,
            'decision' => 'Le mur doit être repris.',
            'decided_by' => 'Direction',
            'decided_at' => '2026-11-06T12:00:00+00:00',
            'appeal_deadline' => '2026-11-21T12:00:00+00:00',
            'outcome' => null,
            'financial' => ['refund_amount' => 5000, 'note' => null, 'refund_pending' => false, 'refund' => ['id' => 'r1', 'reference' => 'REM-1', 'status' => ['value' => 'demande', 'label' => 'Demandé']]],
            'appeal' => null,
            'messages' => [['id' => '1', 'body' => 'Note', 'internal' => true, 'author' => 'Awa Support', 'from_team' => true, 'date' => '2026-11-06T11:00:00+00:00']],
            'proofs' => [],
            'history' => [['id' => '1', 'action' => 'disputes.decided', 'reason' => 'Le mur doit être repris.', 'by' => 'Direction', 'date' => '2026-11-06T12:00:00+00:00']],
            'can' => ['take' => false, 'urgency' => false, 'link_mission' => false, 'message' => true, 'proof' => true, 'decide' => false, 'decide_appeal' => false, 'appeal' => true, 'close' => false, 'request_refund' => false],
        ]])]);

        $this->get('/avis-litiges/litiges/'.self::DISPUTE)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Dispute')
                ->where('dispute.financial.refund.status.tone', 'a')
                ->where('dispute.history.0.label', 'Décision rendue')
                ->where('dispute.actions.appeal', true));
    }

    public function test_decisions_reach_the_api_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/disputes/'.self::DISPUTE.'/decision' => Http::sequence()
                ->push(['message' => 'Refusé.', 'errors' => ['dispute' => ['Le responsable du dossier ne peut pas rendre la décision.']]], 422)
                ->push(['message' => 'Refusé.', 'errors' => ['refund_amount' => ['Ce montant dépasse ce qui peut encore être remboursé.']]], 422)
                ->push(['message' => 'Décision rendue.', 'data' => []]),
        ]);
        $base = '/avis-litiges/litiges/'.self::DISPUTE;

        $this->from($base)->post("{$base}/decision", ['decision' => 'Le mur doit être repris.'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->from($base)->post("{$base}/decision", ['decision' => 'Le mur doit être repris.', 'refund_amount' => '999999'])->assertRedirect($base)->assertSessionHasErrors('refund_amount');
        $this->from($base)->post("{$base}/decision", ['decision' => 'Le mur doit être repris.', 'refund_amount' => ''])->assertRedirect($base)->assertSessionHasNoErrors();

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/decision') && $request['refund_amount'] === null);
    }

    public function test_a_proof_is_uploaded_to_the_api(): void
    {
        Http::fake([self::API.'/disputes/'.self::DISPUTE.'/proofs' => Http::response(['message' => 'Preuve ajoutée.', 'data' => []])]);
        $base = '/avis-litiges/litiges/'.self::DISPUTE;

        $this->from($base)->post("{$base}/preuves", ['file' => UploadedFile::fake()->image('mur.jpg'), 'label' => 'Photo du mur'])->assertRedirect($base)->assertSessionHasNoErrors();

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/proofs') && $request->isMultipart());
    }

    public function test_old_pages_lead_to_the_new_screens(): void
    {
        $this->get('/avis-litiges/litiges/LIT-2026-001/preuves')->assertRedirect('/avis-litiges/litiges');
        $this->get('/avis-litiges/recours')->assertRedirect('/avis-litiges/litiges?statut=en_recours');

        foreach (['mediations', 'rapports', 'rapports/generer', 'notifications', 'audit'] as $path) {
            $this->get("/avis-litiges/{$path}")->assertRedirect('/avis-litiges');
        }
    }
}
