<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Entrepreneurs / Techniciens branchées sur l’API (api/v1/admin/contractors) : liste, fiche et ses onglets,
 * décisions sur le dossier et sur chaque pièce.
 */
class ContractorsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a1f369-0000-7000-8000-0000000000c1';

    private const DOCUMENT = '01a1f369-0000-7000-8000-0000000000d1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['contractors.read', 'contractors.validate', 'contractors.update', 'documents.validate']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'reference' => 'ENT-00012', 'name' => 'BATI-CI Construction', 'kind' => ['value' => 'entreprise', 'label' => 'Entreprise'],
            'phone' => '+225 07 12 34 56 78', 'email' => 'contact@bati-ci.ci', 'city' => ['id' => 'ci1', 'name' => 'Abidjan'],
            'trades' => [['code' => 'macon', 'label' => 'Maçon'], ['code' => 'carreleur', 'label' => 'Carreleur']], 'zones' => ['Abidjan', 'Bingerville'],
            'plan' => ['code' => 'entrepreneur_pro', 'name' => 'Pro', 'max_users' => 20], 'activity' => ['open' => 2, 'total' => 7], 'rating' => ['average' => 4.7, 'count' => 18],
            'status' => ['value' => 'actif', 'label' => 'Actif'], 'verification_status' => ['value' => 'soumis', 'label' => 'Soumis'],
            'verification_state' => ['value' => 'en_verification', 'label' => 'En vérification'], 'has_account' => true,
            'submitted_at' => '2026-11-18T10:00:00+00:00', 'validated_at' => null, 'created_at' => '2026-11-10T09:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listResponse(array $rows): array
    {
        return ['data' => $rows, 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows),
            'counts' => ['brouillon' => 1, 'soumis' => 1, 'en_controle' => 0, 'a_corriger' => 1, 'valide' => 5, 'refuse' => 1],
            'states' => ['verifie' => 4, 'en_verification' => 1, 'a_corriger' => 1, 'rejete' => 1, 'brouillon' => 1, 'suspendu' => 1],
            'trades' => [['code' => 'macon', 'label' => 'Maçon']],
            'zones' => [['id' => 'ci1', 'name' => 'Abidjan']],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        $document = ['id' => self::DOCUMENT, 'type' => ['code' => 'contractor_cni', 'label' => 'Carte nationale d’identité'], 'number_last4' => '0456', 'expires_at' => null, 'version' => 1,
            'status' => ['value' => 'en_attente', 'label' => 'En attente'], 'rejection_reason' => null,
            'files' => [['id' => 'f1', 'side' => 'recto', 'name' => 'cni-recto.png', 'mime_type' => 'image/png', 'size_bytes' => 1000, 'url' => 'https://api.test/media/f1'], ['id' => 'f2', 'side' => 'verso', 'name' => 'cni-verso.png', 'mime_type' => 'image/png', 'size_bytes' => 1000, 'url' => 'https://api.test/media/f2']],
            'created_at' => '2026-11-18T09:00:00+00:00'];
        $type = fn (string $code, string $label, bool $identity, string $hint, bool $provided) => ['code' => $code, 'label' => $label, 'group' => $identity ? 'identity' : $code, 'hint' => $hint, 'is_identity' => $identity, 'has_back_side' => $identity, 'requires_number' => false, 'allowed_mime' => ['image/png'], 'max_size_kb' => 5120, 'provided' => $provided];

        return [
            'id' => self::ID, 'reference' => 'ENT-00012', 'kind' => ['value' => 'entreprise', 'label' => 'Entreprise'], 'verification_type' => 'KYB',
            'name' => 'BATI-CI Construction', 'initials' => 'BC', 'photo_url' => null,
            'company' => ['legal_name' => 'BATI-CI Construction', 'legal_form' => 'SARL', 'rccm' => 'CI-ABJ-2019-B-1234', 'ncc' => '1234567A', 'representative_name' => 'Konan Ahou'],
            'identity' => null,
            'contact' => ['dial_code' => '225', 'phone_number' => '0712345678', 'phone' => '+225 07 12 34 56 78', 'email' => 'contact@bati-ci.ci', 'city' => ['id' => 'ci1', 'name' => 'Abidjan'], 'address' => 'Cocody Angré'],
            'activity' => ['trades' => [['id' => 't1', 'code' => 'macon', 'label' => 'Maçon']], 'zones' => [['id' => 'ci2', 'name' => 'Bingerville']], 'experience_years' => 12, 'description' => 'Gros œuvre'],
            'verification' => ['status' => ['value' => 'soumis', 'label' => 'Soumis'], 'state' => ['value' => 'en_verification', 'label' => 'En vérification'], 'reason' => null, 'submitted_at' => '2026-11-18T10:00:00+00:00', 'validated_at' => null, 'can_edit' => false, 'can_change_kind' => false, 'can_submit' => false, 'edit_warning' => null],
            'limits' => ['can_respond' => false, 'message' => 'Limites…'],
            'completion' => ['percent' => 100, 'done' => 9, 'total' => 9, 'complete' => true, 'items' => [], 'missing' => []],
            'documents' => [$document],
            'document_types' => [
                $type('contractor_cni', 'Carte nationale d’identité', true, 'Obligatoire', true),
                $type('contractor_passport', 'Passeport', true, 'Obligatoire', false),
                $type('contractor_rccm', 'Registre du commerce (RCCM)', false, 'Selon votre statut', false),
            ],
            'options' => ['legal_forms' => []],
            'status' => ['value' => 'actif', 'label' => 'Actif'], 'suspended_reason' => null,
            'account' => ['id' => 'u1', 'status' => ['value' => 'actif', 'label' => 'Actif'], 'phone' => '+225 07 12 34 56 78', 'email' => null, 'last_login_at' => '2026-11-18T11:00:00+00:00', 'created_at' => '2026-11-10T09:00:00+00:00'],
            'plan' => ['code' => 'entrepreneur_solo', 'name' => 'Solo', 'max_users' => 1, 'status' => null, 'current_period_end' => null],
            'rating' => ['average' => null, 'count' => 0], 'completed_sites_count' => 0,
            'reviews' => [['id' => 'r1', 'submission_number' => 1, 'status' => ['value' => 'soumis', 'label' => 'Soumis'], 'submitted_at' => '2026-11-18T10:00:00+00:00', 'decision_reason' => null, 'decided_at' => null, 'decided_by' => null]],
            'actions' => ['approve' => true, 'request_corrections' => true, 'reject' => true, 'review_documents' => true, 'suspend' => true, 'reactivate' => false],
            'created_at' => '2026-11-10T09:00:00+00:00',
            ...$overrides,
        ];
    }

    public function test_the_list_translates_the_tab_and_the_filters_for_the_api(): void
    {
        Http::fake([self::API.'/contractors*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/entrepreneurs?onglet=rejetes&q=bati&type=entreprise&metier=macon&zone=ci1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Contractors/Index')
                ->where('contractors.total', 1)
                ->where('contractors.data.0.code', 'ENT-00012')
                ->where('contractors.data.0.kind', ['value' => 'entreprise', 'label' => 'Entreprise', 'check' => 'KYB'])
                ->where('contractors.data.0.state', ['value' => 'en_verification', 'label' => 'En vérification', 'tone' => 'a'])
                ->where('contractors.data.0.plan', ['name' => 'Pro', 'seats' => 20])
                ->where('contractors.data.0.activity', ['open' => 2, 'total' => 7])
                ->where('tabs.0', ['key' => '', 'label' => 'Tous', 'icon' => 'groups', 'count' => 9])
                ->where('tabs.3', ['key' => 'rejetes', 'label' => 'Rejetés & suspendus', 'icon' => 'block', 'count' => 2])
                ->where('tabs.4.count', 2)
                ->where('kpis', ['verified' => 4, 'total' => 9, 'toReview' => 1, 'corrections' => 1])
                ->where('filterOptions.metier', [['value' => 'macon', 'label' => 'Maçon']])
                ->where('can', ['validate' => true, 'update' => true, 'rules' => false]));

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === ['search' => 'bati', 'kind' => 'entreprise', 'trade' => 'macon', 'zone_city_id' => 'ci1', 'state' => 'rejete,suspendu', 'per_page' => '20'];
        });
    }

    public function test_the_fiche_shows_the_kyb_file_its_documents_and_what_to_do(): void
    {
        Http::fake([self::API.'/contractors/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/entrepreneurs/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Contractors/Show')
                ->where('contractor.name', 'BATI-CI Construction')
                ->where('contractor.kind.check', 'KYB')
                ->where('contractor.identity.1', ['Forme juridique', 'SARL'])
                ->where('contractor.plan.name', 'Solo')
                ->where('contractor.can.reviewDocuments', true)
                ->where('documents.0.id', self::DOCUMENT)
                ->where('documents.0.status', ['value' => 'en_attente', 'label' => 'À vérifier', 'tone' => 'a'])
                ->where('documents.0.required', true)
                ->where('documents.0.canDecide', true)
                ->count('documents.0.files', 2)
                ->where('documents.1.label', 'Registre du commerce (RCCM)')
                ->where('documents.1.hasFile', false)
                ->count('documents', 2)
                ->where('tabCounts.pieces', 1)
                ->where('alerts.0.title', 'Dossier à vérifier')
                ->where('kpis.0.value', '0 / 1')
                ->where('responses', null)
                ->where('header.crumbs.0.label', 'Entrepreneurs / Techniciens'));

        $this->get('/entrepreneurs/'.self::ID.'/inconnu')->assertNotFound();
    }

    public function test_the_responses_and_history_tabs_read_the_api(): void
    {
        Http::fake([
            self::API.'/contractors/'.self::ID.'/responses' => Http::response(['data' => [
                ['id' => 'b1', 'kind' => ['value' => 'demande_devis', 'label' => 'Demande de devis'], 'opportunity' => ['id' => 'q1', 'reference' => 'DV-00031'], 'title' => 'Maçon · Résidence Palmiers', 'promoter' => 'Awa Diallo', 'amount' => 15000, 'amount_label' => 'Frais de visite', 'status' => ['value' => 'proposee', 'label' => 'Proposée'], 'at' => '2026-11-18T12:00:00+00:00'],
            ]]),
            self::API.'/contractors/'.self::ID.'/history' => Http::response(['data' => [
                ['id' => 'h1', 'kind' => ['value' => 'contractor', 'label' => 'Entrepreneur'], 'author' => 'BATI-CI Construction', 'what' => 'a soumis son dossier', 'detail' => 'Soumission n° 1', 'at' => '2026-11-18T10:00:00+00:00'],
            ]]),
            self::API.'/contractors/'.self::ID => Http::response(['data' => $this->detail()]),
        ]);

        $this->get('/entrepreneurs/'.self::ID.'/reponses')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'reponses')
                ->where('responses.0.reference', 'DV-00031')
                ->where('responses.0.status', ['label' => 'Proposée', 'tone' => 'b'])
                ->where('responses.0.open', true));

        $this->get('/entrepreneurs/'.self::ID.'/historique')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'historique')
                ->where('history.0.action', 'a soumis son dossier')
                ->where('history.0.category.label', 'Entrepreneur')
                ->where('history.0.comment', 'Soumission n° 1'));
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/contractors/'.self::ID.'/approve' => Http::response(['message' => 'Confirmez d’abord la pièce d’identité.', 'errors' => ['contractor' => ['Confirmez d’abord la pièce d’identité.']]], 422),
            self::API.'/contractors/'.self::ID.'/documents/'.self::DOCUMENT.'/approve' => Http::response(['message' => 'Pièce confirmée.', 'data' => []]),
            self::API.'/contractors/'.self::ID.'/documents/'.self::DOCUMENT.'/reject' => Http::response(['message' => 'Pièce rejetée.', 'data' => []]),
            self::API.'/contractors/'.self::ID.'/corrections' => Http::response(['message' => 'Corrections demandées à l’entrepreneur.', 'data' => []]),
            self::API.'/contractors/'.self::ID.'/suspension' => Http::response(['message' => 'Le motif est trop court.', 'errors' => ['reason' => ['Le motif est trop court.']]], 422),
        ]);
        $page = '/entrepreneurs/'.self::ID.'/pieces';

        $this->from($page)->post('/entrepreneurs/'.self::ID.'/validation')
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toastError', 'Confirmez d’abord la pièce d’identité.');
        $this->from($page)->post('/entrepreneurs/'.self::ID.'/pieces/'.self::DOCUMENT.'/confirmation')->assertSessionHas('inertia.flash_data.toast', 'Pièce confirmée.');
        $this->from($page)->post('/entrepreneurs/'.self::ID.'/pieces/'.self::DOCUMENT.'/rejet', ['category' => 'Document illisible', 'reason' => 'Verso flou.'])->assertSessionHas('inertia.flash_data.toast', 'Pièce rejetée.');
        $this->from($page)->post('/entrepreneurs/'.self::ID.'/complement', ['reason' => 'Merci d’ajouter le RCCM.', 'document_ids' => [self::DOCUMENT]])->assertSessionHas('inertia.flash_data.toast', 'Corrections demandées à l’entrepreneur.');
        $this->from($page)->post('/entrepreneurs/'.self::ID.'/suspension', ['category' => 'Autre', 'reason' => 'abc'])->assertSessionHasErrors(['reason' => 'Le motif est trop court.']);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/documents/'.self::DOCUMENT.'/reject') && $r['reason'] === 'Document illisible · Verso flou.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/corrections') && $r['reason'] === 'Merci d’ajouter le RCCM.' && $r['document_ids'] === [self::DOCUMENT]);
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['contractors.read']);
        Http::fake([
            self::API.'/contractors/'.self::ID => Http::response(['data' => $this->detail()]),
            self::API.'/contractors*' => Http::response($this->listResponse([$this->row()])),
        ]);

        $this->get('/entrepreneurs')->assertInertia(fn (Assert $page) => $page->where('can', ['validate' => false, 'update' => false, 'rules' => false]));
        $this->get('/entrepreneurs/'.self::ID)->assertInertia(fn (Assert $page) => $page->where('can', ['validate' => false, 'update' => false, 'documents' => false, 'finance' => false, 'payouts' => false]));
    }
}
