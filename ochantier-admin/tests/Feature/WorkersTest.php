<?php

namespace Tests\Feature;

use App\Models\Worker;
use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Ouvriers branchées sur l’API (api/v1/admin/workers) : liste, export, fiche et décisions.
 */
class WorkersTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000a1';

    private const DOCUMENT = '01a16ab6-0000-7000-8000-0000000000d1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['workers.read', 'workers.create', 'workers.validate', 'workers.update', 'workers.export', 'documents.validate']);
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
            'id' => self::ID, 'reference' => 'OUV-000001', 'first_name' => 'Jean', 'last_name' => 'Kouassi', 'full_name' => 'Jean Kouassi',
            'phone' => '+225 07 00 00 00 99', 'photo_url' => null, 'trade' => ['id' => 't1', 'code' => 'macon', 'label' => 'Maçon'],
            'experience_years' => 6, 'level' => ['value' => 'confirme', 'label' => 'Confirmé'], 'daily_rate' => 12000,
            'rating' => ['average' => 4.5, 'count' => 2], 'availability' => ['value' => 'disponible', 'label' => 'Disponible'],
            'status' => ['value' => 'soumis', 'label' => 'Soumis'], 'commune' => ['id' => 'c1', 'name' => 'Cocody'],
            'commercial' => ['id' => 'cm1', 'full_name' => 'Yao Konan'], 'expired_documents_count' => 0, 'identity_mismatch' => false,
            'submitted_at' => '2026-11-02T10:00:00+00:00', 'validated_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        $document = ['id' => self::DOCUMENT, 'type' => ['code' => 'worker_cni', 'label' => 'Carte nationale d’identité'], 'number_last4' => '5678', 'expires_at' => null, 'version' => 1,
            'status' => ['value' => 'en_attente', 'label' => 'En attente'], 'files' => [['id' => 'f1', 'side' => 'recto', 'name' => 'cni-recto.png', 'mime_type' => 'image/png', 'size_bytes' => 1000, 'url' => 'https://api.test/media/f1']],
            'created_at' => '2026-11-02T09:00:00+00:00', 'rejection_reason' => null];

        return [
            ...$this->row(),
            'identity' => ['gender' => ['value' => 'M', 'label' => 'Homme'], 'birth_date' => '1990-05-12', 'birth_place' => 'Bouaké', 'nationality' => 'Côte d’Ivoire'],
            'contact' => ['dial_code' => '225', 'phone_number' => '0700000099', 'phone' => '+225 07 00 00 00 99', 'secondary_phone' => null, 'emergency_contact' => ['name' => 'Marie Kouassi', 'relation' => 'Épouse', 'phone' => '+225 07 11 22 33 44']],
            'residence' => ['commune' => ['id' => 'c1', 'name' => 'Cocody'], 'neighborhood' => ['id' => 'n1', 'name' => 'Angré'], 'address' => 'Rue des Jardins', 'landmark' => null],
            'trades' => [
                ['trade' => ['id' => 't1', 'code' => 'macon', 'label' => 'Maçon'], 'is_primary' => true, 'experience_years' => 6, 'level' => ['value' => 'confirme', 'label' => 'Confirmé']],
                ['trade' => ['id' => 't2', 'code' => 'carreleur', 'label' => 'Carreleur'], 'is_primary' => false, 'experience_years' => 2, 'level' => null],
            ],
            'specialties' => [['id' => 's1', 'label' => 'Maçonnerie de fondation']],
            'skills' => [['id' => 'k1', 'label' => 'Lecture de plans']],
            'references' => [['project' => 'Résidence Les Palmiers', 'role' => 'Maçon', 'employer' => 'Bâtir Afrique', 'start_date' => '2024-01-10', 'end_date' => '2024-06-30']],
            'work' => ['mobility_radius_km' => 15, 'accepts_long_travel' => false, 'needs_accommodation' => false, 'transport_mode' => 'transport_commun', 'works_solo' => true, 'works_in_team' => true, 'accepted_offer_types' => ['ponctuelle'], 'accepted_payment_frequencies' => ['journalier'], 'available_from' => null],
            'recruitment' => ['origin_commercial' => ['id' => 'cm1', 'full_name' => 'Yao Konan', 'phone' => '+225 05 00 00 00 01'], 'current_commercial' => ['id' => 'cm1', 'full_name' => 'Yao Konan', 'phone' => '+225 05 00 00 00 01'], 'acquisition_channel' => 'terrain'],
            'stats' => ['missions_completed' => 3, 'attendance_rate' => 96.5, 'acceptance_rate' => 80.0, 'replacement_count' => 0, 'last_assignment_at' => null],
            'documents' => [
                'slots' => [
                    ['key' => 'identity', 'label' => 'Pièce d’identité', 'required' => true, 'state' => ['value' => 'en_attente', 'label' => 'En attente'], 'document' => $document],
                    ['key' => 'worker_photo', 'label' => 'Photo d’identité', 'required' => true, 'state' => ['value' => 'manquant', 'label' => 'Manquant'], 'document' => null],
                ],
                'others' => [],
            ],
            'review' => [
                'can_decide' => true, 'can_approve' => false,
                'approval_refusal' => 'Pièces à vérifier avant la validation : Pièce d’identité, Photo d’identité.',
                'required_documents' => [],
                'current' => ['id' => 'r1', 'submission_number' => 1, 'status' => ['value' => 'soumis', 'label' => 'Soumis'], 'submitted_at' => '2026-11-02T10:00:00+00:00', 'assigned_admin' => null, 'decided_by' => null, 'decided_at' => null, 'decision_reason' => null, 'corrections' => []],
                'history' => [],
            ],
            'sanction' => null,
            'validated_by' => null,
            'missing' => [],
            'can' => ['suspend' => false, 'reactivate' => false, 'exclude' => true, 'edit' => true],
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function listResponse(array $rows): array
    {
        return ['data' => $rows, 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows),
            'counts' => ['total' => 4, 'statuses' => ['soumis' => 1, 'valide' => 3], 'availability' => ['disponible' => 2, 'occupe' => 1, 'indisponible' => 0], 'todo' => ['a_verifier' => 1, 'a_corriger' => 0, 'pieces_expirees' => 2]],
            'trades' => [['id' => 't1', 'label' => 'Maçon']],
            'communes' => [['id' => 'c1', 'name' => 'Cocody']],
        ]];
    }

    public function test_the_list_translates_the_filters_and_shows_the_api_rows(): void
    {
        Http::fake([self::API.'/workers*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/ouvriers?q=kouassi&metier=t1&cout=15000-25000&note=4.5&rapide=verification&tri=cout&sens=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workers/Index')
                ->where('workers.total', 1)
                ->where('workers.data.0.slug', self::ID)
                ->where('workers.data.0.code', 'OUV-000001')
                ->where('workers.data.0.trade', 'Maçon')
                ->where('workers.data.0.status', ['value' => 'soumis', 'label' => 'À vérifier', 'tone' => 'a'])
                ->where('kpis', ['total' => 4, 'available' => 2, 'onMission' => 1, 'toReview' => 1])
                ->where('todo', ['review' => 1, 'corrections' => 0, 'documents' => 2])
                ->where('filterOptions.metier', [['value' => 't1', 'label' => 'Maçon']])
                ->where('can.export', true));

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === ['search' => 'kouassi', 'trade_id' => 't1', 'daily_rate_min' => '15000', 'daily_rate_max' => '25000', 'rating_min' => '4.5', 'todo' => 'a_verifier', 'sort' => 'daily_rate', 'direction' => 'desc', 'per_page' => '20'];
        });
    }

    public function test_the_export_relays_the_api_csv(): void
    {
        Http::fake([self::API.'/workers/export*' => Http::response("\xEF\xBB\xBFRéférence;Nom\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="ouvriers-2026-11-03.csv"'])]);

        $this->get('/ouvriers/export?statut=valide&ids='.self::ID)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ouvriers-2026-11-03.csv"')
            ->assertSee('Référence;Nom', false);

        Http::assertSent(fn (ClientRequest $request) => str_contains(urldecode($request->url()), 'ids[0]='.self::ID) && str_contains($request->url(), 'status=valide'));
    }

    public function test_the_fiche_shows_the_dossier_its_documents_and_what_blocks_the_validation(): void
    {
        Http::fake([self::API.'/workers/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/ouvriers/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workers/Show')
                ->where('worker.name', 'Jean Kouassi')
                ->where('worker.level', 'Confirmé')
                ->where('worker.secondaryTrades', ['Carreleur · 2 ans'])
                ->where('worker.emergencyContact', 'Marie Kouassi · Épouse')
                ->where('worker.review.canDecide', true)
                ->where('worker.review.current.number', 1)
                ->where('documents.0.id', self::DOCUMENT)
                ->where('documents.0.status', ['value' => 'en_attente', 'label' => 'À vérifier', 'tone' => 'a'])
                ->where('documents.0.reference', '•••• 5678')
                ->where('documents.0.canDecide', true)
                ->where('documents.1.id', 'worker_photo')
                ->where('documents.1.hasFile', false)
                ->where('tabCounts.identite-documents', 1)
                ->where('alerts.0.title', 'Dossier à vérifier')
                ->where('kpis.2.value', '97')
                ->where('can.validate', true));

        $this->get('/ouvriers/'.self::ID.'/metiers-competences')->assertInertia(fn (Assert $page) => $page->where('tab', 'metiers-competences')->where('worker.references.0.period', '10 janv. 2024 → 30 juin 2024'));
        $this->get('/ouvriers/'.self::ID.'/inconnu')->assertRedirect('/ouvriers/'.self::ID);
    }

    public function test_old_links_lead_to_the_search_or_the_list(): void
    {
        Worker::factory()->create(['slug' => 'kouame-koffi', 'first_name' => 'Kouamé', 'last_name' => 'Koffi']);

        $this->get('/ouvriers/kouame-koffi/pointages')->assertRedirect('/ouvriers?q='.rawurlencode('Kouamé Koffi'));

        Http::assertNothingSent();
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/workers/'.self::ID.'/approve' => Http::response(['message' => 'Pièces manquantes', 'errors' => ['worker' => ['Pièces manquantes : Photo d’identité.']]], 422),
            self::API.'/workers/'.self::ID.'/documents/'.self::DOCUMENT.'/approve' => Http::response(['message' => 'Pièce vérifiée.', 'data' => []]),
            self::API.'/workers/'.self::ID.'/corrections' => Http::response(['message' => 'Corrections demandées au commercial.', 'data' => []]),
            self::API.'/workers/'.self::ID.'/suspension' => Http::response(['message' => 'Ouvrier suspendu jusqu’au 20 novembre 2026.', 'data' => []]),
            self::API.'/workers/'.self::ID.'/exclusion' => Http::response(['message' => 'Indiquez le motif.', 'errors' => ['reason' => ['Indiquez le motif.']]], 422),
            self::API.'/workers/approve' => Http::response(['message' => 'Aucun dossier n’a pu être validé.', 'data' => ['approved' => [], 'skipped' => [['id' => self::ID, 'full_name' => 'Jean Kouassi', 'reason' => 'Pièces manquantes : Photo d’identité.']]]]),
        ]);
        $page = '/ouvriers/'.self::ID.'/identite-documents';

        $this->from($page)->post('/ouvriers/'.self::ID.'/validation')
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toastError', 'Pièces manquantes : Photo d’identité.');
        $this->from($page)->post('/ouvriers/'.self::ID.'/documents/'.self::DOCUMENT.'/validation')->assertSessionHas('inertia.flash_data.toast', 'Pièce vérifiée.');

        $corrections = [['document_id' => self::DOCUMENT, 'reason' => 'Photo floue.'], ['field' => 'daily_rate', 'reason' => 'Coût à revoir.']];
        $this->from($page)->post('/ouvriers/'.self::ID.'/corrections', ['corrections' => $corrections, 'message' => null])->assertSessionHas('inertia.flash_data.toast', 'Corrections demandées au commercial.');

        $this->from($page)->post('/ouvriers/'.self::ID.'/suspension', ['category' => 'Absences répétées', 'reason' => 'Trois jours sans prévenir.', 'until' => '2026-11-20'])
            ->assertSessionHas('inertia.flash_data.toast', 'Ouvrier suspendu jusqu’au 20 novembre 2026.');
        $this->from($page)->post('/ouvriers/'.self::ID.'/exclusion', ['category' => 'Fraude avérée', 'reason' => ''])->assertSessionHasErrors(['reason' => 'Indiquez le motif.']);

        $this->from('/ouvriers')->post('/ouvriers/validation-groupee', ['ids' => [self::ID]])
            ->assertSessionHas('inertia.flash_data.toastError', 'Jean Kouassi : Pièces manquantes : Photo d’identité.');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/corrections') && $r['corrections'] === $corrections);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/suspension') && $r['reason'] === 'Absences répétées · Trois jours sans prévenir.' && $r['until'] === '2026-11-20');
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['workers.read']);
        Http::fake([
            self::API.'/workers/'.self::ID => Http::response(['data' => $this->detail()]),
            self::API.'/workers*' => Http::response($this->listResponse([$this->row()])),
        ]);

        $this->get('/ouvriers')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'validate' => false, 'update' => false, 'export' => false]));
        $this->get('/ouvriers/'.self::ID)->assertInertia(fn (Assert $page) => $page->where('can', ['validate' => false, 'update' => false, 'documents' => false]));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return ['data' => [
            'commercials' => [['id' => 'cm1', 'full_name' => 'Yao Konan']],
            'trades' => [
                ['id' => 't1', 'code' => 'macon', 'label' => 'Maçon', 'specialties' => [['id' => 's1', 'label' => 'Fondations']], 'skills' => [['id' => 'k1', 'label' => 'Lecture de plans']]],
                ['id' => 't2', 'code' => 'peintre', 'label' => 'Peintre', 'specialties' => [], 'skills' => []],
            ],
            'cities' => [['id' => 'v1', 'name' => 'Abidjan', 'communes' => [['id' => 'c1', 'name' => 'Cocody', 'neighborhoods' => [['id' => 'n1', 'name' => 'Angré']]]]]],
            'document_types' => [['code' => 'worker_photo', 'label' => 'Photo d’identité', 'is_identity' => false, 'is_required' => true, 'has_back_side' => false, 'allowed_mime' => ['image/jpeg'], 'max_size_kb' => 5120]],
            'genders' => [['value' => 'M', 'label' => 'Homme'], ['value' => 'F', 'label' => 'Femme']],
            'offer_types' => [['value' => 'ponctuelle', 'label' => 'Ponctuelle']],
            'payment_frequencies' => [['value' => 'journalier', 'label' => 'Journalier']],
        ]];
    }

    public function test_the_creation_form_lists_the_options_and_sends_the_api_body(): void
    {
        Http::fake([
            self::API.'/workers/form-options' => Http::response($this->formOptions()),
            self::API.'/workers' => Http::response(['message' => 'Ouvrier enregistré : ajoutez les pièces manquantes pour transmettre le dossier.', 'data' => ['id' => self::ID]], 201),
        ]);

        $this->get('/ouvriers/nouveau')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workers/Form')
                ->where('mode', 'create')
                ->where('options.commercial', [['value' => 'cm1', 'label' => 'Yao Konan']])
                ->where('options.commune', [['value' => 'c1', 'label' => 'Cocody · Abidjan']])
                ->where('neighborhoods.c1', [['value' => 'n1', 'label' => 'Angré']])
                ->where('tradeLists.t1.skills', [['value' => 'k1', 'label' => 'Lecture de plans']]));

        $this->post('/ouvriers', [
            'commercial_id' => 'cm1', 'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'phone' => '+225 07 08 09 10 11',
            'residence_commune_id' => 'c1', 'emergency_phone' => '01 02 03 04 05', 'trade_id' => 't1', 'experience_years' => '8',
            'secondary_trade_id' => '', 'secondary_experience_years' => '3', 'daily_rate' => '15 000', 'skill_ids' => ['k1'],
            'works_solo' => true, 'works_in_team' => false, 'accepted_offer_types' => ['ponctuelle'],
            'references' => [['project' => 'Résidence Les Palmiers', 'role' => '', 'employer' => '', 'start_date' => '', 'end_date' => ''], ['project' => '  ', 'role' => 'x']],
        ])
            ->assertRedirect('/ouvriers/'.self::ID.'/identite-documents')
            ->assertSessionHas('inertia.flash_data.toast', 'Ouvrier enregistré : ajoutez les pièces manquantes pour transmettre le dossier.');

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/workers')
            && $r['dial_code'] === '225' && $r['phone_number'] === '0708091011'
            && $r['emergency_contact_phone_number'] === '0102030405'
            && $r['experience_years'] === 8 && $r['daily_rate'] === 15000
            && $r['secondary_trade_id'] === null && $r['secondary_experience_years'] === null
            && $r['works_in_team'] === false && $r['skill_ids'] === ['k1']
            && $r['references'] === [['project' => 'Résidence Les Palmiers', 'role' => null, 'employer' => null, 'start_date' => null, 'end_date' => null]]);
    }

    public function test_api_errors_come_back_on_the_form_fields(): void
    {
        Http::fake([self::API.'/workers' => Http::response(['message' => 'Erreur', 'errors' => [
            'phone_number' => ['Ce numéro est déjà inscrit sur O’CHANTIER.'],
            'emergency_contact_phone_number' => ['Le contact d’urgence doit avoir un autre numéro que l’ouvrier.'],
            'skill_ids.0' => ['Cette compétence ne correspond pas aux métiers choisis.'],
        ]], 422)]);

        $this->from('/ouvriers/nouveau')->post('/ouvriers', ['first_name' => 'Ibrahim'])->assertSessionHasErrors([
            'phone' => 'Ce numéro est déjà inscrit sur O’CHANTIER.',
            'emergency_phone' => 'Le contact d’urgence doit avoir un autre numéro que l’ouvrier.',
            'skill_ids' => 'Cette compétence ne correspond pas aux métiers choisis.',
        ]);
    }

    public function test_the_edit_form_is_prefilled_and_the_update_is_sent_to_the_api(): void
    {
        Http::fake([
            self::API.'/workers/form-options' => Http::response($this->formOptions()),
            self::API.'/workers/'.self::ID => fn (ClientRequest $request) => $request->method() === 'PATCH'
                ? Http::response(['message' => 'Ouvrier mis à jour.', 'data' => ['id' => self::ID]])
                : Http::response(['data' => $this->detail()]),
        ]);

        $this->get('/ouvriers/'.self::ID.'/modifier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workers/Form')
                ->where('mode', 'edit')
                ->where('worker.name', 'Jean Kouassi')
                ->where('values.commercial_id', 'cm1')
                ->where('values.phone', '+225 07 00 00 00 99')
                ->where('values.emergency_phone', '')
                ->where('values.trade_id', 't1')
                ->where('values.secondary_trade_id', 't2')
                ->where('values.experience_years', '6')
                ->where('values.specialty_ids', ['s1'])
                ->where('values.references.0.project', 'Résidence Les Palmiers'));

        $this->patch('/ouvriers/'.self::ID, ['commercial_id' => 'cm1', 'first_name' => 'Jean', 'last_name' => 'Kouassi', 'phone' => '0700000099', 'daily_rate' => '18000'])
            ->assertRedirect('/ouvriers/'.self::ID)
            ->assertSessionHas('inertia.flash_data.toast', 'Ouvrier mis à jour.');

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'PATCH' && $r['daily_rate'] === 18000 && $r['phone_number'] === '0700000099');
    }

    public function test_a_document_is_uploaded_to_the_api_with_its_files(): void
    {
        Http::fake([self::API.'/workers/'.self::ID.'/documents' => Http::response(['message' => 'Photo d’identité ajoutée au dossier.', 'data' => ['id' => self::ID]], 201)]);

        $this->post('/ouvriers/'.self::ID.'/documents', ['document_type' => 'worker_photo', 'recto' => UploadedFile::fake()->image('photo.jpg')])
            ->assertRedirect('/ouvriers/'.self::ID.'/identite-documents')
            ->assertSessionHas('inertia.flash_data.toast', 'Photo d’identité ajoutée au dossier.');

        Http::assertSent(fn (ClientRequest $request) => $request->isMultipart()
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'file' && $part['filename'] === 'photo.jpg')
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'document_type' && $part['contents'] === 'worker_photo'));
    }

    public function test_the_read_only_tabs_load_only_their_own_data_from_the_api(): void
    {
        $assignment = ['id' => 'as1', 'mission' => ['id' => 'm1', 'reference' => 'MIS-0001', 'status' => ['value' => 'active', 'label' => 'Active']], 'site' => ['id' => 's1', 'title' => 'Résidence Les Palmiers'], 'promoter' => 'Bâtir Afrique',
            'trade' => 'Maçon', 'role' => null, 'is_team_lead' => true, 'start_date' => '2026-11-10', 'end_date' => '2026-11-20', 'actual_start_at' => null, 'actual_end_at' => null,
            'promoter_daily_rate' => 25000, 'worker_net_daily_rate' => 22000, 'worked_days' => 3, 'validated_days' => 2, 'replaces' => false, 'end_reason' => null, 'status' => ['value' => 'confirmee', 'label' => 'Confirmée']];
        $page = ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1];

        Http::fake([
            self::API.'/workers/'.self::ID => Http::response(['data' => $this->detail()]),
            self::API.'/workers/'.self::ID.'/assignments*' => Http::response(['data' => [$assignment], 'meta' => [...$page, 'counts' => ['all' => 1, 'confirmee' => 1]]]),
            self::API.'/workers/'.self::ID.'/earnings' => Http::response(['data' => [['id' => 'as1', 'mission' => $assignment['mission'], 'site' => $assignment['site'], 'start_date' => '2026-11-10', 'end_date' => '2026-11-20', 'worker_net_daily_rate' => 22000, 'validated_days' => 2, 'status' => $assignment['status'], 'earned' => 44000, 'paid' => 0, 'remaining' => 44000]],
                'meta' => ['totals' => ['earned' => 44000, 'paid' => 0, 'remaining' => 44000], 'payouts' => []]]),
            self::API.'/workers/'.self::ID.'/history*' => Http::response(['data' => [
                ['id' => 'audit:1', 'at' => now()->toIso8601String(), 'origin' => ['value' => 'admin', 'label' => 'Back Office'], 'author' => 'Awa Koné', 'what' => 'a validé le dossier', 'detail' => null, 'from' => 'en_controle', 'to' => 'valide'],
            ], 'meta' => $page]),
        ]);

        $this->get('/ouvriers/'.self::ID.'/affectations?statut=confirmee')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'affectations')
                ->where('relations.kind', 'assignments')
                ->where('relations.rows.0.trade', 'Maçon · chef d’équipe')
                ->where('relations.rows.0.days', '2 / 3')
                ->where('relations.rows.0.rate', '22 000 FCFA')
                ->where('relations.rows.0.status.tone', 'b')
                ->where('relations.filters.1.count', 1));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/assignments?') && str_contains($r->url(), 'status=confirmee'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/earnings'));

        $this->get('/ouvriers/'.self::ID.'/paiements')
            ->assertInertia(fn (Assert $page) => $page->where('relations.kind', 'earnings')->where('relations.totals.0.value', '44 000 FCFA')->where('relations.rows.0.days', 2)->where('relations.payouts', []));

        $this->get('/ouvriers/'.self::ID.'/historique?origine=admin')
            ->assertInertia(fn (Assert $page) => $page->where('relations.origin', 'admin')
                ->where('relations.rows.0.day', 'Aujourd’hui')
                ->where('relations.rows.0.icon', 'desktop_windows')
                ->where('relations.rows.0.target', 'En vérification → Validé'));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/history?') && str_contains($r->url(), 'origin=admin'));
    }

    public function test_the_mobile_money_number_needs_the_payout_permission(): void
    {
        Http::fake([
            self::API.'/workers/'.self::ID => Http::response(['data' => $this->detail()]),
            self::API.'/workers/'.self::ID.'/earnings' => Http::response(['data' => [], 'meta' => ['totals' => ['earned' => 0, 'paid' => 0, 'remaining' => 0], 'payouts' => []]]),
            self::API.'/workers/'.self::ID.'/payment-method' => Http::response(['data' => ['current' => null, 'pending' => null, 'operators' => [['id' => 'o1', 'name' => 'Orange Money']], 'default_number' => ['dial_code' => '225', 'phone_number' => '0712345678']]]),
        ]);

        $this->signIn(['workers.read']);
        $this->get('/ouvriers/'.self::ID.'/paiements')->assertOk()->assertInertia(fn (Assert $page) => $page->where('relations.paymentMethod', null));
        Http::assertNotSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/payment-method'));

        $this->signIn(['workers.read', 'payouts.read', 'payouts.create']);
        auth()->forgetGuards();
        $this->get('/ouvriers/'.self::ID.'/paiements')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('relations.paymentMethod.operators.0.name', 'Orange Money')
            ->where('relations.paymentMethod.can.create', true)
            ->where('relations.paymentMethod.can.approve', false));
    }
}
