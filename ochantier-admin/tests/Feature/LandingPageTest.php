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
 * Page Landing Page branchée sur l’API (api/v1/admin/landing) : vue d’ensemble, candidatures (dont la création du
 * profil ouvrier préremplie), messages de contact et newsletter.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const APPLICATION = '01a2002c-0000-7000-8000-0000000000a1';

    private const MESSAGE = '01a2002c-0000-7000-8000-0000000000b1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['workers.read', 'workers.validate', 'workers.create', 'tickets.read', 'tickets.update', 'content.read']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        return ['data' => [
            'applications' => ['nouvelle' => 2, 'en_verification' => 1, 'convertie' => 3, 'refusee' => 0],
            'messages' => ['nouveau' => 1, 'en_cours' => 0, 'traite' => 4, 'late' => 1, 'response_hours' => 24],
            'newsletter' => ['active' => 12, 'month' => 3],
        ]];
    }

    /**
     * État de l’éditeur de contenu renvoyé par l’API (schéma et textes réels de la version 1).
     *
     * @return array<string, mixed>
     */
    private function editor(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/landing-content.json')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function application(): array
    {
        return [
            'id' => self::APPLICATION, 'reference' => 'CAND-000001', 'full_name' => 'Ibrahim Ouattara', 'phone' => '+225 07 48 12 33 90',
            'trade' => ['label' => 'Maçon', 'matched' => ['id' => 't1', 'label' => 'Maçon']], 'commune' => ['label' => 'Cocody', 'matched' => ['id' => 'c1', 'name' => 'Cocody']],
            'experience_years' => 8, 'level' => 'Confirmé', 'availability' => 'Immédiate', 'target_site' => null,
            'status' => ['value' => 'nouvelle', 'label' => 'Nouvelle'], 'duplicates_count' => 1, 'worker' => null, 'created_at' => '2026-11-21T09:00:00+00:00',
            'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'email' => null, 'birth_date' => '1992-03-14', 'gender' => 'Homme', 'daily_rate' => 25000,
            'skills' => ['Coffrage'], 'certifications' => [], 'bio' => null, 'neighborhood' => 'Angré', 'mobility' => '10 km', 'available_days' => ['Lun'],
            'payout' => ['method' => 'Orange Money', 'number' => null], 'target_site_commune' => null, 'refusal_reason' => null, 'handled_by' => null,
            'files' => ['identity' => ['name' => 'cni.jpg', 'mime_type' => 'image/jpeg', 'url' => 'https://api.test/m/1'], 'photo' => null],
            'duplicates' => ['applications' => [['id' => 'a2', 'reference' => 'CAND-000002', 'status' => ['value' => 'nouvelle', 'label' => 'Nouvelle'], 'created_at' => '2026-11-20T09:00:00+00:00']], 'workers' => []],
            'prefill' => ['first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'dial_code' => '225', 'phone_number' => '0748123390', 'gender' => 'M', 'trade_id' => 't1', 'residence_commune_id' => 'c1', 'experience_years' => 8, 'daily_rate' => 25000],
            'actions' => ['review' => true, 'refuse' => true, 'convert' => true],
        ];
    }

    public function test_the_tabs_follow_the_rights_and_the_overview_counts_what_to_handle(): void
    {
        Http::fake([self::API.'/landing/overview' => Http::response($this->overview()), self::API.'/landing/content' => Http::response(['data' => $this->editor()])]);

        $this->get('/landing-page')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Index')
                ->where('tab', '')
                ->has('tabs', 6)
                ->where('tabs.2', ['key' => 'candidatures', 'label' => 'Candidatures ouvriers', 'icon' => 'engineering', 'count' => 3])
                ->where('tabs.3.count', 1)
                ->where('overview.newsletter.active', 12)
                ->where('site.live.number', 1)
                ->where('site.visible', 7)
                ->where('site.sections.2.key', 'demo')
                ->where('site.sections.2.number', '01')
                ->where('draft.changes', [])
                ->missing('editor'));
    }

    public function test_a_tab_without_its_right_is_forbidden(): void
    {
        $this->signIn(['tickets.read']);
        Http::fake([self::API.'/landing/overview' => Http::response(['data' => ['messages' => $this->overview()['data']['messages']]])]);

        $this->get('/landing-page/candidatures')->assertForbidden();
        $this->get('/landing-page')->assertInertia(fn (Assert $page) => $page->has('tabs', 2)->where('tabs.1.key', 'messages')->where('draft', null)->where('site', null));
        $this->get('/landing-page/contenu/cover')->assertForbidden();
    }

    public function test_the_application_list_and_detail_read_the_api(): void
    {
        Http::fake([
            self::API.'/landing/overview' => Http::response($this->overview()),
            self::API.'/landing/content' => Http::response(['data' => $this->editor()]),
            self::API.'/landing/applications/'.self::APPLICATION => Http::response(['data' => $this->application()]),
            self::API.'/landing/applications*' => Http::response(['data' => [$this->application()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['nouvelle' => 1], 'trades' => ['Maçon']]]),
        ]);

        $this->get('/landing-page/candidatures?statut=nouvelle&q=ouattara&metier=Maçon')
            ->assertInertia(fn (Assert $page) => $page->where('list.data.0.reference', 'CAND-000001')->where('list.data.0.duplicates', 1)->where('list.trades', ['Maçon']));
        Http::assertSent(fn (ClientRequest $r) => str_contains(urldecode($r->url()), 'status=nouvelle') && str_contains($r->url(), 'search=ouattara'));

        $this->get('/landing-page/candidatures/'.self::APPLICATION)
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Application')
                ->where('application.duplicateApplications.0.reference', 'CAND-000002')
                ->where('application.work.0', ['Commune', 'Cocody'])
                ->where('application.can.convert', true)
                ->where('header.crumbs.2.label', 'CAND-000001'));
    }

    public function test_the_worker_form_is_prefilled_from_the_application_and_linked_on_save(): void
    {
        Http::fake([
            self::API.'/landing/applications/'.self::APPLICATION => Http::response(['data' => $this->application()]),
            self::API.'/workers/form-options' => Http::response(['data' => ['commercials' => [], 'trades' => [], 'cities' => [], 'genders' => [], 'offer_types' => [], 'payment_frequencies' => []]]),
            self::API.'/workers' => Http::response(['message' => 'Ouvrier enregistré.', 'data' => ['id' => '01a2002c-0000-7000-8000-0000000000c9']], 201),
        ]);

        $this->get('/ouvriers/nouveau?candidature='.self::APPLICATION)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workers/Form')
                ->where('source', ['reference' => 'CAND-000001', 'name' => 'Ibrahim Ouattara', 'id' => self::APPLICATION])
                ->where('values.first_name', 'Ibrahim')
                ->where('values.phone', '+225 0748123390')
                ->where('values.trade_id', 't1')
                ->where('values.experience_years', '8')
                ->where('values.landing_application_id', self::APPLICATION));

        $this->post('/ouvriers', ['landing_application_id' => self::APPLICATION, 'commercial_id' => 'cm1', 'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'phone' => '+225 07 48 12 33 90'])
            ->assertRedirect('/ouvriers/01a2002c-0000-7000-8000-0000000000c9/identite-documents');

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/workers') && $r['landing_application_id'] === self::APPLICATION);
    }

    public function test_decisions_and_message_handling_are_sent_to_the_api(): void
    {
        Http::fake([
            self::API.'/landing/applications/'.self::APPLICATION.'/refuse' => Http::response(['message' => 'Cette candidature est déjà traitée.', 'errors' => ['application' => ['Cette candidature est déjà traitée.']]], 422),
            self::API.'/landing/messages/'.self::MESSAGE.'/status' => Http::response(['message' => 'Message traité.', 'data' => []]),
            self::API.'/landing/newsletter/export' => Http::response("\xEF\xBB\xBFE-mail;Inscrit le\n", 200, ['Content-Disposition' => 'attachment; filename="newsletter-2026-11-21.csv"']),
        ]);
        $page = '/landing-page/candidatures/'.self::APPLICATION;

        $this->from($page)->post($page.'/refus', ['category' => 'Autre', 'reason' => 'Doublon.'])->assertRedirect($page)->assertSessionHas('inertia.flash_data.toastError', 'Cette candidature est déjà traitée.');
        $this->from('/landing-page/messages/'.self::MESSAGE)->post('/landing-page/messages/'.self::MESSAGE.'/statut', ['status' => 'traite', 'note' => 'Rappelé.'])->assertSessionHas('inertia.flash_data.toast', 'Message traité.');
        $this->get('/landing-page/newsletter/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="newsletter-2026-11-21.csv"');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/status') && $r['status'] === 'traite' && $r['note'] === 'Rappelé.');
    }

    public function test_the_content_editor_shows_a_section_and_the_draft_banner(): void
    {
        $editor = $this->editor();
        $editor['draft']['sections']['tarifs']['title'] = 'Une formule pour chaque équipe, sans engagement.';
        $editor['draft']['hidden'] = ['carte'];
        $editor['changes'] = [['key' => 'tarifs', 'label' => 'Tarifs'], ['key' => 'layout', 'label' => 'Ordre et affichage des sections']];
        $editor['checks'] = [['level' => 'error', 'section' => 'tarifs', 'message' => '« Titre » est obligatoire.']];
        $editor['draft_updated_by'] = 'Awa Koné';
        Http::fake([self::API.'/landing/overview' => Http::response($this->overview()), self::API.'/landing/content' => Http::response(['data' => $editor])]);

        $this->get('/landing-page/contenu')->assertRedirect('/landing-page/contenu/cover');
        $this->get('/landing-page/contenu/inconnue')->assertRedirect('/landing-page/contenu/cover');

        $this->get('/landing-page/contenu/tarifs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Content')
                ->where('tab', 'contenu')
                ->where('tabs.1.count', 2)
                ->where('editor.section.key', 'tarifs')
                ->where('editor.section.changed', true)
                ->where('editor.section.values.title', 'Une formule pour chaque équipe, sans engagement.')
                ->has('editor.section.fields', 3)
                ->has('editor.nav', 12)
                ->where('editor.nav.6', ['key' => 'carte', 'label' => 'Carte des chantiers', 'icon' => 'map', 'hidden' => true, 'changed' => false, 'error' => false])
                ->where('editor.checks.0.sectionLabel', 'Tarifs')
                ->where('editor.layout.hidden', ['carte'])
                ->where('draft.errors', 1)
                ->where('draft.firstError', 'tarifs')
                ->where('draft.updatedBy', 'Awa Koné')
                ->where('draft.canEdit', false));
    }

    public function test_editor_actions_are_sent_to_the_api(): void
    {
        $this->signIn(['content.read', 'content.update']);
        Http::fake([
            self::API.'/landing/content/sections/contact' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['email' => ['Saisissez une adresse e-mail valide.'], 'profiles' => ['Ajoutez au moins 1 élément(s).']]], 422),
            self::API.'/landing/content/sections/tarifs' => Http::response(['message' => 'Brouillon enregistré.', 'data' => []]),
            self::API.'/landing/content/layout' => Http::response(['message' => 'Brouillon enregistré.', 'data' => []]),
            self::API.'/landing/content/publish' => Http::response(['message' => 'Publication impossible : Tarifs · « Titre » est obligatoire.', 'errors' => ['content' => ['Publication impossible : Tarifs · « Titre » est obligatoire.']]], 422),
            self::API.'/landing/content/versions/1/restore' => Http::response(['message' => 'Version 1 restaurée dans le brouillon : publiez pour mettre le site à jour.', 'data' => []]),
            self::API.'/landing/content/files' => Http::response(['message' => 'Fichier envoyé.', 'data' => ['id' => '01a2004e-89b8-717f-a7be-59712dd71b75', 'name' => 'qr.png', 'mime_type' => 'image/png', 'url' => 'https://api.test/m/1']], 201),
        ]);
        $page = '/landing-page/contenu/contact';

        $this->from($page)->put($page, ['values' => ['email' => 'x']])->assertRedirect($page)->assertSessionHasErrors(['email' => 'Saisissez une adresse e-mail valide.', 'profiles']);
        $this->from('/landing-page/contenu/tarifs')->put('/landing-page/contenu/tarifs', ['values' => ['kick' => 'Abonnements', 'title' => 'Titre']])->assertSessionHas('inertia.flash_data.toast', 'Brouillon enregistré.');
        $this->from('/landing-page')->put('/landing-page/structure', ['order' => ['solution', 'demo'], 'hidden' => ['carte']])->assertRedirect('/landing-page');
        $this->from('/landing-page')->post('/landing-page/publier', ['note' => ' Tarifs '])->assertSessionHas('inertia.flash_data.toastError', 'Publication impossible : Tarifs · « Titre » est obligatoire.');
        $this->from('/landing-page/publications')->post('/landing-page/publications/1/restaurer')->assertSessionHas('inertia.flash_data.toast');
        $this->from($page)->post('/landing-page/fichiers', ['file' => UploadedFile::fake()->image('qr.png'), 'accept' => 'image/*', 'field' => 'qr'])
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.upload.id', '01a2004e-89b8-717f-a7be-59712dd71b75');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/landing/content/sections/tarifs') && $r->method() === 'PUT' && $r['values']['title'] === 'Titre');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/landing/content/layout') && $r['hidden'] === ['carte']);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/landing/content/publish') && $r['note'] === 'Tarifs');
    }

    public function test_the_publications_tab_lists_the_versions(): void
    {
        Http::fake([
            self::API.'/landing/overview' => Http::response($this->overview()),
            self::API.'/landing/content' => Http::response(['data' => $this->editor()]),
            self::API.'/landing/content/versions*' => Http::response(['data' => [
                ['number' => 2, 'note' => 'Tarifs', 'sections' => ['Tarifs'], 'restored_from' => null, 'published_by' => 'Awa Koné', 'published_at' => '2026-11-21T11:00:00+00:00', 'live' => true],
                ['number' => 1, 'note' => 'Version initiale : textes du site.', 'sections' => [], 'restored_from' => null, 'published_by' => null, 'published_at' => '2026-11-21T10:00:00+00:00', 'live' => false],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2]]),
        ]);

        $this->get('/landing-page/publications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Publications')
                ->where('tab', 'publications')
                ->where('list.total', 2)
                ->where('list.data.0.live', true)
                ->where('list.data.1.publishedBy', 'Mise en service'));
    }
}
