<?php

namespace Tests\Feature;

use App\Models\Promoter;
use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
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

    private const SITE = '7c1e0000-0000-4000-8000-000000000001';

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
        $this->get('/promoteurs/'.self::ID.'/litiges')->assertRedirect('/promoteurs/'.self::ID);

        Http::assertNothingSent();
    }

    public function test_the_read_only_tabs_load_only_their_own_data_from_the_api(): void
    {
        $page = ['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 21];
        Http::fake([
            self::API.'/promoters/'.self::ID => Http::response($this->detail()),
            self::API.'/promoters/'.self::ID.'/sites*' => Http::response(['data' => [[
                'id' => self::SITE, 'reference' => 'CH-0001', 'title' => 'Résidence Les Palmiers', 'work_type' => ['value' => 'construction', 'label' => 'Construction'],
                'property_type' => 'Villa', 'address' => 'Angré', 'commune' => 'Cocody', 'neighborhood' => 'Angré', 'start_date' => '2026-11-10', 'end_date' => '2026-11-20',
                'duration_days' => 10, 'schedule' => ['label' => 'Démarrage dans 7 jours', 'tag' => 'Planifié'], 'responsible' => 'Nathanaël Yao',
                'offers_count' => 1, 'missions_count' => 3, 'status' => ['value' => 'confirme', 'label' => 'Confirmé'],
            ]], 'meta' => [...$page, 'counts' => ['all' => 21, 'confirme' => 21]]]),
            self::API.'/promoters/'.self::ID.'/payments*' => Http::response(['data' => [[
                'key' => 'schedule:1', 'kind' => ['value' => 'acompte', 'label' => 'Acompte'], 'short' => 'Acompte (30 %)', 'title' => 'Acompte · Résidence', 'site' => ['id' => self::SITE, 'title' => 'Résidence Les Palmiers'],
                'who' => '3 ouvriers', 'amount' => 150000, 'status' => ['value' => 'effectue', 'label' => 'Effectué'], 'when' => null, 'paid_at' => '2026-11-02T10:00:00+00:00',
                'method' => 'Orange Money', 'transaction_reference' => 'PAY-1', 'receipt' => ['number' => 'R-1', 'url' => 'https://api.test/media/r1'],
            ]], 'meta' => ['totals' => ['a_regler' => 0, 'en_retard' => 0, 'a_venir' => 300000, 'payes' => 150000]]]),
            self::API.'/promoters/'.self::ID.'/activity*' => Http::response(['data' => [
                ['id' => 'e1', 'action' => 'promoter.approved', 'kind' => ['value' => 'admin', 'label' => 'Administrateur'], 'author' => 'Awa Koné', 'role' => null, 'what' => 'a validé le dossier', 'site' => null, 'detail' => null, 'at' => now()->toIso8601String()],
                ['id' => 'e2', 'action' => 'sites.publish', 'kind' => ['value' => 'promoter', 'label' => 'Promoteur'], 'author' => 'Nathanaël Yao', 'role' => 'Titulaire', 'what' => 'a publié le chantier', 'site' => 'Résidence', 'detail' => null, 'at' => now()->subDays(3)->toIso8601String()],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 30, 'total' => 2]]),
            self::API.'/promoters/'.self::ID.'/disputes*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]),
        ]);

        $this->get('/promoteurs/'.self::ID.'/chantiers?statut=confirme&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'chantiers')
                ->where('relations.kind', 'sites')
                ->where('relations.rows.0.location', 'Angré · Cocody')
                ->where('relations.rows.0.period', '10 nov. 2026 → 20 nov. 2026')
                ->where('relations.rows.0.status.tone', 'g')
                ->where('relations.pagination.total', 21)
                ->where('relations.filters.0.count', 21)
                ->where('alerts', []));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/sites?') && str_contains($request->url(), 'status=confirme') && str_contains($request->url(), 'page=2'));
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/payments'));

        $this->get('/promoteurs/'.self::ID.'/paiements?chantier='.self::SITE)
            ->assertInertia(fn (Assert $page) => $page->where('relations.kind', 'payments')
                ->where('relations.site.title', 'Résidence Les Palmiers')
                ->where('relations.rows.0.amount', '150 000 FCFA')
                ->where('relations.rows.0.receipt', 'https://api.test/media/r1')
                ->where('relations.totals.1.value', '300 000 FCFA'));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'payments?site_id='.self::SITE));

        $this->get('/promoteurs/'.self::ID.'/activite?auteur=promoteur')
            ->assertInertia(fn (Assert $page) => $page->where('relations.author', 'promoteur')
                ->where('relations.rows.0.day', 'Aujourd’hui')
                ->where('relations.rows.0.icon', 'verified')
                ->where('relations.rows.1.icon', 'construction')
                ->where('relations.rows.1.kind.value', 'promoteur'));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'activity?') && str_contains($request->url(), 'author=promoter'));

        $this->get('/promoteurs/'.self::ID.'/avis?vue=litiges')
            ->assertInertia(fn (Assert $page) => $page->where('relations.kind', 'disputes')->where('relations.rows', []));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return ['message' => null, 'data' => [
            'profile_kinds' => [['value' => 'particulier', 'label' => 'Particulier'], ['value' => 'technicien', 'label' => 'Technicien'], ['value' => 'entreprise', 'label' => 'Entreprise']],
            'cities' => [['id' => 'v1', 'name' => 'Abidjan', 'communes' => [['id' => 'c1', 'name' => 'Cocody', 'neighborhoods' => [['id' => 'n1', 'name' => 'Angré']]]]]],
            'document_types' => [
                ['code' => 'promoter_cni', 'label' => 'Carte nationale d’identité', 'audience' => 'promoter', 'is_identity' => true, 'is_required' => true, 'has_back_side' => true, 'allowed_mime' => ['image/jpeg'], 'max_size_kb' => 5120],
                ['code' => 'organization_rccm', 'label' => 'Extrait RCCM', 'audience' => 'organization', 'is_identity' => false, 'is_required' => true, 'has_back_side' => false, 'allowed_mime' => ['application/pdf'], 'max_size_kb' => 5120],
            ],
        ]];
    }

    public function test_the_creation_form_lists_the_options_and_the_company_is_sent_grouped_to_the_api(): void
    {
        Http::fake([
            self::API.'/promoters/form-options' => Http::response($this->formOptions()),
            self::API.'/promoters' => Http::response(['message' => 'Compte promoteur créé : invitation envoyée par SMS.', 'data' => ['id' => self::ID]], 201),
        ]);

        $this->get('/promoteurs/nouveau')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Promoters/Form')
                ->where('mode', 'create')
                ->where('options.commune.0', ['value' => 'c1', 'label' => 'Cocody · Abidjan'])
                ->where('neighborhoods.c1.0.label', 'Angré')
                ->where('values.send_invitation', true));

        $this->post('/promoteurs', [
            'profile_kind' => 'entreprise', 'first_name' => 'Konan', 'last_name' => 'Kouassi', 'phone' => '+225 07 01 02 03 04', 'email' => '',
            'company_legal_name' => 'Bâtir Afrique', 'company_rccm' => 'CI-ABJ-2024-B-12345', 'hq_address' => 'Rue des Jardins', 'hq_commune_id' => 'c1', 'send_invitation' => true,
        ])
            ->assertRedirect('/promoteurs/'.self::ID)
            ->assertSessionHas('inertia.flash_data.toast', 'Compte promoteur créé : invitation envoyée par SMS.');

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
            && $request['dial_code'] === '225' && $request['phone_number'] === '0701020304' && $request['email'] === null
            && $request['company']['legal_name'] === 'Bâtir Afrique' && $request['company']['headquarters'] === ['address' => 'Rue des Jardins', 'commune_id' => 'c1', 'neighborhood_id' => null]
            && $request['send_invitation'] === true);
    }

    public function test_api_errors_come_back_on_the_form_fields(): void
    {
        Http::fake([self::API.'/promoters' => Http::response(['message' => 'Erreur', 'errors' => ['phone_number' => ['Ce numéro est déjà utilisé par un autre compte.'], 'company.legal_name' => ['Indiquez la raison sociale.']]], 422)]);

        $this->from('/promoteurs/nouveau')->post('/promoteurs', ['profile_kind' => 'entreprise', 'first_name' => 'Konan', 'last_name' => 'Kouassi', 'phone' => '+225 07 12 34 56 34'])
            ->assertRedirect('/promoteurs/nouveau')
            ->assertSessionHasErrors(['phone' => 'Ce numéro est déjà utilisé par un autre compte.', 'company_legal_name' => 'Indiquez la raison sociale.']);
    }

    public function test_the_edit_form_is_prefilled_and_the_update_is_sent_to_the_api(): void
    {
        Http::fake([
            self::API.'/promoters/form-options' => Http::response($this->formOptions()),
            self::API.'/promoters/'.self::ID => function (ClientRequest $request) {
                return $request->method() === 'PATCH'
                    ? Http::response(['message' => 'Promoteur mis à jour.', 'data' => ['id' => self::ID]])
                    : Http::response($this->detail(['dial_code' => '225', 'phone_number' => '0712345634', 'verification' => ['can_change_kind' => false, 'state' => ['value' => 'en_verification', 'label' => 'En vérification'], 'status' => ['value' => 'soumis', 'label' => 'Soumis']]]));
            },
        ]);

        $this->get('/promoteurs/'.self::ID.'/modifier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Promoters/Form')
                ->where('mode', 'edit')
                ->where('canChangeKind', false)
                ->where('values.phone', '+225 07 12 34 56 34')
                ->where('values.commune_id', 'c1')
                ->where('values.address', 'Angré 8e tranche'));

        $this->patch('/promoteurs/'.self::ID, ['profile_kind' => 'particulier', 'first_name' => 'Nathanaël', 'last_name' => 'Yao-Koffi', 'phone' => '', 'email' => 'n.yao@exemple.ci', 'commune_id' => 'c1'])
            ->assertRedirect('/promoteurs/'.self::ID)
            ->assertSessionHas('inertia.flash_data.toast', 'Promoteur mis à jour.');

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PATCH' && $request['last_name'] === 'Yao-Koffi' && $request['phone_number'] === null && $request['email'] === 'n.yao@exemple.ci' && ! isset($request['company']));
    }

    public function test_a_document_is_uploaded_to_the_api_with_its_files(): void
    {
        Http::fake([self::API.'/promoters/'.self::ID.'/documents' => Http::response(['message' => 'Extrait RCCM ajouté au dossier.', 'data' => ['id' => self::ID]], 201)]);

        $this->post('/promoteurs/'.self::ID.'/documents', ['document_type' => 'organization_rccm', 'recto' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf')])
            ->assertRedirect('/promoteurs/'.self::ID.'/kyc')
            ->assertSessionHas('inertia.flash_data.toast', 'Extrait RCCM ajouté au dossier.');

        Http::assertSent(fn (ClientRequest $request) => $request->isMultipart()
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'file' && $part['filename'] === 'rccm.pdf')
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'document_type' && $part['contents'] === 'organization_rccm'));
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        Http::fake([self::API.'/promoters/'.self::ID => Http::response($this->detail())]);

        $this->get('/promoteurs/'.self::ID)->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'addDocument' => false])->missing('documentTypes'));
    }
}
