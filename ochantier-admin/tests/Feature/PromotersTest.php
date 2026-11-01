<?php

namespace Tests\Feature;

use App\Models\Promoter;
use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Promoteurs branchées sur l’API (api/v1/admin/promoters) : liste, export, fiche et décisions.
 */
class PromotersTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-000000000001';

    private const DOCUMENT = '01a16ab6-0000-7000-8000-0000000000d1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();

        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => [], 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'full_name' => 'Nathanaël Yao', 'phone' => '+225 07 12 34 56 34', 'email' => null,
            'kind' => ['value' => 'particulier', 'label' => 'Particulier'], 'company' => null, 'commune' => ['id' => 'c1', 'name' => 'Cocody'],
            'status' => ['value' => 'soumis', 'label' => 'Soumis'], 'verification_status' => ['value' => 'soumis', 'label' => 'Soumis'],
            'account_status' => ['value' => 'actif', 'label' => 'Actif'], 'sites_count' => 2, 'expired_documents_count' => 1, 'open_disputes_count' => 0,
            'registered_at' => '2026-10-20T09:00:00+00:00', 'validated_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listResponse(array $rows): array
    {
        return ['message' => null, 'data' => $rows, 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows),
            'counts' => ['total' => 3, 'statuses' => ['brouillon' => 1, 'soumis' => 1, 'en_controle' => 0, 'a_corriger' => 0, 'valide' => 0, 'refuse' => 0, 'suspendu' => 1], 'todo' => ['a_verifier' => 1, 'incomplets' => 1, 'pieces_expirees' => 1, 'signales' => 0]],
            'communes' => [['id' => 'c1', 'name' => 'Cocody']],
        ]];
    }

    /**
     * Fiche telle que renvoyée par l’API : particulier soumis, pièce d’identité en attente, certificat manquant.
     *
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        $document = [
            'id' => self::DOCUMENT, 'type' => ['code' => 'promoter_cni', 'label' => 'Carte nationale d’identité'], 'number_last4' => '4521', 'expires_at' => '2030-01-31',
            'version' => 1, 'status' => ['value' => 'en_attente', 'label' => 'En attente'], 'rejection_reason' => null, 'created_at' => '2026-10-20T09:00:00+00:00',
            'files' => [['id' => 'f1', 'side' => 'recto', 'name' => 'recto.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1200, 'url' => 'https://api.test/media/f1']],
        ];

        return ['message' => null, 'data' => [
            'id' => self::ID, 'kind' => ['value' => 'particulier', 'label' => 'Particulier'], 'first_name' => 'Nathanaël', 'last_name' => 'Yao', 'full_name' => 'Nathanaël Yao',
            'phone' => '+225 07 12 34 56 34', 'email' => null, 'job_title' => null,
            'address' => ['address' => 'Angré 8e tranche', 'commune' => ['id' => 'c1', 'name' => 'Cocody'], 'neighborhood' => null],
            'company' => null,
            'verification' => ['state' => ['value' => 'en_verification', 'label' => 'En vérification'], 'status' => ['value' => 'soumis', 'label' => 'Soumis']],
            'completion' => ['percent' => 67, 'complete' => false, 'missing' => [['key' => 'residence', 'label' => 'Certificat de résidence']]],
            'documents' => ['slots' => [
                ['key' => 'identity', 'label' => 'Pièce d’identité', 'required' => true, 'state' => ['value' => 'en_verification', 'label' => 'En vérification'], 'document' => $document],
                ['key' => 'promoter_residence_certificate', 'label' => 'Certificat de résidence', 'required' => true, 'state' => ['value' => 'manquant', 'label' => 'Manquant'], 'document' => null],
            ], 'others' => []],
            'stats' => ['sites' => 2, 'active_sites' => 1, 'users' => 1],
            'status' => ['value' => 'soumis', 'label' => 'Soumis'],
            'account' => ['status' => ['value' => 'actif', 'label' => 'Actif'], 'suspended_at' => null, 'suspended_reason' => null, 'registered_at' => '2026-10-20T09:00:00+00:00', 'last_login_at' => null, 'validated_at' => null, 'validated_by' => null],
            'review' => [
                'can_decide' => true, 'can_approve' => false, 'approval_refusal' => 'Pièces à vérifier avant la validation : Pièce d’identité.',
                'required_documents' => [], 'history' => [],
                'current' => ['id' => 'r1', 'submission_number' => 1, 'status' => ['value' => 'soumis', 'label' => 'Soumis'], 'submitted_at' => '2026-10-20T09:00:00+00:00', 'assigned_admin' => null, 'decided_by' => null, 'decided_at' => null, 'decision_reason' => null, 'corrections' => []],
            ],
            'subscription' => null,
            'flags' => ['expired_documents_count' => 0, 'open_disputes_count' => 0],
            ...$overrides,
        ]];
    }

    public function test_the_list_translates_the_filters_and_shows_the_api_rows(): void
    {
        Http::fake([self::API.'/promoters*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/promoteurs?q=yao&statut=soumis&rapide=a_verifier&tri=promoteur&sens=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Promoters/Index')
                ->where('promoters.total', 1)
                ->where('promoters.data.0.slug', self::ID)
                ->where('promoters.data.0.representative', 'Nathanaël Yao')
                ->where('promoters.data.0.status', ['value' => 'soumis', 'label' => 'Soumis', 'tone' => 'b'])
                ->where('promoters.data.0.expiredDocuments', 1)
                ->where('kpis', ['total' => 3, 'active' => 0, 'pending' => 1, 'suspended' => 1])
                ->where('todo.a_verifier', 1)
                ->where('filterOptions.localisation', [['value' => 'c1', 'label' => 'Cocody']]));

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), self::API.'/promoters?')
                && $query === ['search' => 'yao', 'status' => 'soumis', 'todo' => 'a_verifier', 'sort' => 'name', 'direction' => 'asc', 'per_page' => '20'];
        });
    }

    public function test_the_export_relays_the_api_csv(): void
    {
        Http::fake([self::API.'/promoters/export*' => Http::response("\xEF\xBB\xBFNom;Téléphone\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename=promoteurs-2026-11-01.csv'])]);

        $this->get('/promoteurs/export?statut=soumis&ids='.self::ID)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=promoteurs-2026-11-01.csv')
            ->assertSee('Nom;Téléphone', false);

        Http::assertSent(fn (ClientRequest $request) => str_contains(urldecode($request->url()), 'ids[0]='.self::ID) && str_contains($request->url(), 'status=soumis'));
    }

    public function test_the_detail_shows_the_file_and_the_review(): void
    {
        Http::fake([self::API.'/promoters/'.self::ID => Http::response($this->detail())]);

        $this->get('/promoteurs/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Promoters/Show')
                ->where('promoter.name', 'Nathanaël Yao')
                ->where('promoter.isCompany', false)
                ->where('promoter.status.tone', 'b')
                ->where('promoter.review.canDecide', true)
                ->where('documents.0.id', self::DOCUMENT)
                ->where('documents.0.status.label', 'À vérifier')
                ->where('documents.0.reference', '•••• 4521')
                ->where('documents.0.canValidate', true)
                ->where('documents.1.status.label', 'Manquant')
                ->where('documents.1.hasFile', false)
                ->where('tabCounts.kyc', 2)
                ->where('alerts.0.title', 'Dossier à vérifier')
                ->where('alerts.1.title', 'Pièce manquante')
                ->where('kpis.1.value', '67 %'));

        $this->get('/promoteurs/'.self::ID.'/kyc/'.self::DOCUMENT)
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'kyc')->where('drawer.document.files.0.url', 'https://api.test/media/f1'));

        $this->get('/promoteurs/'.self::ID.'/kyc/inconnu/autre')->assertRedirect('/promoteurs/'.self::ID);
    }

    public function test_decisions_are_sent_to_the_api_and_its_message_is_shown(): void
    {
        Http::fake([
            self::API.'/promoters/'.self::ID.'/documents/'.self::DOCUMENT.'/approve' => Http::response(['message' => 'Pièce vérifiée.', 'data' => []]),
            self::API.'/promoters/'.self::ID.'/suspension' => Http::response(['message' => 'Compte suspendu.', 'data' => []]),
            self::API.'/promoters/'.self::ID.'/corrections' => Http::response(['message' => 'Corrections demandées au promoteur.', 'data' => []]),
            self::API.'/promoters/'.self::ID.'/messages' => Http::response(['message' => 'Message envoyé au promoteur.', 'data' => null]),
        ]);
        $page = '/promoteurs/'.self::ID.'/kyc';

        $this->from($page)->post('/promoteurs/'.self::ID.'/documents/'.self::DOCUMENT.'/validation')
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toast', 'Pièce vérifiée.');

        $this->from($page)->post('/promoteurs/'.self::ID.'/suspension', ['category' => 'Retards de paiement', 'reason' => 'Trois échéances impayées.'])
            ->assertSessionHas('inertia.flash_data.toast', 'Compte suspendu.');

        $corrections = [['document_id' => self::DOCUMENT, 'reason' => 'Photo floue.']];
        $this->from($page)->post('/promoteurs/'.self::ID.'/corrections', ['corrections' => $corrections, 'message' => null])->assertRedirect($page);

        $this->from($page)->post('/promoteurs/'.self::ID.'/notifications', ['subject' => 'Bienvenue', 'message' => 'Bonjour !'])->assertRedirect($page);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/suspension') && $r['reason'] === 'Retards de paiement · Trois échéances impayées.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/corrections') && $r['corrections'] === $corrections);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/messages') && $r['title'] === 'Bienvenue' && $r['body'] === 'Bonjour !');
    }

    public function test_a_refused_decision_becomes_an_error_notification_and_form_errors_stay_on_the_form(): void
    {
        Http::fake([
            self::API.'/promoters/'.self::ID.'/approve' => Http::response(['message' => 'Pièces à vérifier', 'errors' => ['promoter' => ['Pièces à vérifier avant la validation : Pièce d’identité.']]], 422),
            self::API.'/promoters/'.self::ID.'/reject' => Http::response(['message' => 'Indiquez le motif.', 'errors' => ['reason' => ['Indiquez le motif.']]], 422),
        ]);
        $page = '/promoteurs/'.self::ID;

        $this->from($page)->post($page.'/validation')
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toastError', 'Pièces à vérifier avant la validation : Pièce d’identité.')
            ->assertSessionDoesntHaveErrors();

        $this->from($page)->post($page.'/refus', ['reason' => ''])->assertSessionHasErrors(['reason' => 'Indiquez le motif.']);
    }

    public function test_bulk_approval_reports_the_skipped_files(): void
    {
        Http::fake([self::API.'/promoters/approve' => Http::response(['message' => '1 compte validé.', 'data' => [
            'approved' => [self::ID],
            'skipped' => [['id' => 'x', 'full_name' => 'Awa Bamba', 'reason' => 'Le dossier est incomplet.']],
        ]])]);

        $this->from('/promoteurs')->post('/promoteurs/validation-groupee', ['ids' => [self::ID, '01a16ab6-0000-7000-8000-000000000002']])
            ->assertSessionHas('inertia.flash_data.toast', '1 compte validé.')
            ->assertSessionHas('inertia.flash_data.toastError', 'Awa Bamba : Le dossier est incomplet.');
    }

    public function test_old_links_lead_to_the_search_or_the_overview_instead_of_a_missing_page(): void
    {
        Promoter::factory()->create(['slug' => 'batir-afrique', 'name' => 'Bâtir Afrique']);

        $this->get('/promoteurs/batir-afrique/paiements/PAY-260918-1702')
            ->assertRedirect('/promoteurs?q='.rawurlencode('Bâtir Afrique'))
            ->assertSessionHas('inertia.flash_data.toast');
        $this->get('/promoteurs/horizon-btp')->assertRedirect('/promoteurs?q=Horizon%20Btp');
        $this->get('/promoteurs/'.self::ID.'/chantiers')->assertRedirect('/promoteurs/'.self::ID);

        Http::assertNothingSent();
    }
}
