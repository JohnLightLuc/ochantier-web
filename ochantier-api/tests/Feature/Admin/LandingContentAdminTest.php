<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\V1\Landing\ContactMessageRequest;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\LandingContentVersion;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * Éditeur de contenu du site public : brouillon par section, contrôles, publication en versions, restauration,
 * fichiers, et lecture du contenu en ligne par le serveur du site.
 */
class LandingContentAdminTest extends CommercialTestCase
{
    private string $editor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.landing.key' => 'cle-du-site']);
        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->editor = $this->tokenFor(Admin::factory()->role('support')->create());
    }

    public function test_version_one_holds_the_site_texts_and_the_draft_starts_identical(): void
    {
        $this->withAccessToken($this->editor)->getJson('/api/v1/admin/landing/content')
            ->assertOk()
            ->assertJsonCount(12, 'data.schema')
            ->assertJsonPath('data.live.number', 1)
            ->assertJsonPath('data.draft.sections.cover.title', 'Trouvez vos prochains ouvriers.')
            ->assertJsonPath('data.draft.order', ['demo', 'solution', 'paiement', 'tarifs', 'carte', 'app', 'contact'])
            ->assertJsonPath('data.changes', [])
            ->assertJsonPath('data.checks', []);

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'Aucune modification']);
    }

    public function test_a_section_is_saved_to_the_draft_then_published_as_a_new_version(): void
    {
        $values = $this->section('tarifs');
        $values['title'] = 'Une formule pour chaque équipe, sans engagement.';
        $values['unknown'] = 'écarté';

        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/tarifs', ['values' => $values])
            ->assertOk()
            ->assertJsonPath('data.changes', [['key' => 'tarifs', 'label' => 'Tarifs']])
            ->assertJsonMissingPath('data.draft.sections.tarifs.unknown');

        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/layout', ['order' => ['solution', 'demo', 'paiement', 'tarifs', 'carte', 'app', 'contact'], 'hidden' => ['carte']])
            ->assertOk()
            ->assertJsonPath('data.changes.1.key', 'layout')
            ->assertJsonPath('data.checks.0.level', 'warning');

        $this->getJson('/api/v1/landing/content', ['X-Landing-Key' => 'cle-du-site'])->assertJsonPath('data.version', 1);

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish', ['note' => 'Tarifs sans engagement'])
            ->assertOk()
            ->assertJsonPath('message', 'Version 2 publiée : le site est à jour.')
            ->assertJsonPath('data.live.number', 2)
            ->assertJsonPath('data.changes', []);

        $this->getJson('/api/v1/landing/content', ['X-Landing-Key' => 'cle-du-site'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.content.sections.tarifs.title', 'Une formule pour chaque équipe, sans engagement.')
            ->assertJsonPath('data.content.hidden', ['carte']);
        $this->assertSame(['tarifs', 'layout'], LandingContentVersion::query()->where('number', 2)->value('changed_sections'));
        $this->assertTrue(AuditLog::query()->where('action', 'landing.publish')->exists());

        $this->withAccessToken($this->editor)->getJson('/api/v1/admin/landing/content/versions')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.sections', ['Tarifs', 'Ordre et affichage des sections'])
            ->assertJsonPath('data.0.note', 'Tarifs sans engagement')
            ->assertJsonPath('data.0.live', true);

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/versions/1/restore')
            ->assertOk()
            ->assertJsonPath('data.restored_from', 1)
            ->assertJsonPath('data.draft.sections.tarifs.title', 'Une formule pour chaque équipe.');
        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish')->assertOk();
        $this->assertSame(1, LandingContentVersion::query()->where('number', 3)->value('restored_from'));
    }

    public function test_invalid_values_and_blocking_errors_are_refused(): void
    {
        $values = $this->section('contact');
        $values['email'] = 'pas-un-email';
        $values['profiles'] = [];

        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/contact', ['values' => $values])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'profiles']);

        $cover = $this->section('cover');
        $cover['cta'] = '';
        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/cover', ['values' => $cover])
            ->assertOk()
            ->assertJsonPath('data.checks.0', ['level' => 'error', 'section' => 'cover', 'message' => '« Bouton principal » est obligatoire.']);

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'Publication impossible']);

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/sections/cover/reset')->assertOk()->assertJsonPath('data.checks', []);
        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/inconnue', ['values' => []])->assertNotFound();
    }

    public function test_a_read_only_admin_sees_the_editor_but_cannot_change_it(): void
    {
        $readOnly = $this->tokenFor(Admin::factory()->role('lecture_seule')->create());

        $this->withAccessToken($readOnly)->getJson('/api/v1/admin/landing/content')->assertOk();
        $this->withAccessToken($readOnly)->putJson('/api/v1/admin/landing/content/sections/tarifs', ['values' => ['kick' => null, 'title' => 'Titre', 'text' => null]])->assertForbidden();
        $this->withAccessToken($readOnly)->postJson('/api/v1/admin/landing/content/publish')->assertForbidden();
    }

    public function test_an_uploaded_file_is_served_to_the_site_once_published(): void
    {
        Storage::fake(config('filesystems.media'));

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/files', ['accept' => 'image/*', 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $id = $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/files', ['accept' => 'image/*', 'file' => UploadedFile::fake()->image('qr.png')])
            ->assertCreated()
            ->json('data.id');

        $app = $this->section('app');
        $app['qr'] = $id;
        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/app', ['values' => $app])->assertOk()->assertJsonPath("data.files.{$id}.name", 'qr.png');
        $this->get("/api/v1/landing/files/{$id}", ['X-Landing-Key' => 'cle-du-site'])->assertNotFound();

        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish')->assertOk();
        $this->get("/api/v1/landing/files/{$id}", ['X-Landing-Key' => 'cle-du-site'])->assertOk();
        $this->get("/api/v1/landing/files/{$id}")->assertForbidden();
    }

    public function test_the_site_reads_the_active_promoter_plans_and_the_published_profiles(): void
    {
        $this->seed(CommercialOfferSeeder::class);

        $plans = $this->getJson('/api/v1/landing/content', ['X-Landing-Key' => 'cle-du-site'])->assertOk()->json('data.plans');
        $this->assertNotEmpty($plans);
        $this->assertContains('equipe', array_column($plans, 'code'));

        $contact = $this->section('contact');
        $contact['profiles'] = ['Promoteur immobilier', 'Architecte'];
        $this->withAccessToken($this->editor)->putJson('/api/v1/admin/landing/content/sections/contact', ['values' => $contact])->assertOk();
        $this->withAccessToken($this->editor)->postJson('/api/v1/admin/landing/content/publish')->assertOk();

        $rules = (new ContactMessageRequest)->rules()['profile'];
        $this->assertTrue(validator(['profile' => 'Architecte'], ['profile' => $rules])->passes());
        $this->assertTrue(validator(['profile' => 'Ouvrier'], ['profile' => $rules])->passes(), 'Les profils d’origine restent acceptés.');
        $this->assertFalse(validator(['profile' => 'Inconnu'], ['profile' => $rules])->passes());
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        return $this->withAccessToken($this->editor)->getJson('/api/v1/admin/landing/content')->json("data.draft.sections.{$key}");
    }

    private function tokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }
}
