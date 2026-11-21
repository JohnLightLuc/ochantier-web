<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\LandingWorkerApplication;
use App\Models\NewsletterSubscriber;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * Demandes du site public : dépôt par le serveur du site (clé partagée), puis traitement dans le Back Office
 * (candidatures vérifiées, refusées ou converties en profil ouvrier, messages de contact, newsletter).
 */
class LandingLeadAdminTest extends CommercialTestCase
{
    private string $operations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.landing.key' => 'cle-du-site']);
        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->operations = $this->tokenFor(Admin::factory()->role('operations')->create());
    }

    public function test_the_site_needs_its_key_to_post_forms(): void
    {
        $this->postJson('/api/v1/landing/newsletter', ['email' => 'awa@exemple.ci'])->assertForbidden();
        $this->postJson('/api/v1/landing/newsletter', ['email' => 'awa@exemple.ci'], ['X-Landing-Key' => 'mauvaise'])->assertForbidden();

        $this->postJson('/api/v1/landing/newsletter', ['email' => 'Awa@Exemple.ci'], ['X-Landing-Key' => 'cle-du-site'])->assertCreated();
        $this->postJson('/api/v1/landing/newsletter', ['email' => 'awa@exemple.ci'], ['X-Landing-Key' => 'cle-du-site'])->assertCreated();
        $this->assertSame(1, NewsletterSubscriber::query()->where('email', 'awa@exemple.ci')->count());

        config(['services.landing.key' => null]);
        $this->postJson('/api/v1/landing/newsletter', ['email' => 'awa@exemple.ci'], ['X-Landing-Key' => ''])->assertForbidden();
    }

    public function test_an_application_is_reviewed_then_converted_into_a_worker_with_its_files(): void
    {
        $this->apply()->assertCreated()->assertJsonPath('data.reference', 'CAND-000001');
        $this->apply(['first_name' => 'Adama'])->assertCreated();

        $list = $this->withAccessToken($this->operations)->getJson('/api/v1/admin/landing/applications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.nouvelle', 2)
            ->assertJsonPath('data.0.duplicates_count', 1)
            ->assertJsonPath('data.0.trade.matched.label', 'Maçon')
            ->json('data');
        $id = collect($list)->firstWhere('reference', 'CAND-000001')['id'];

        $this->withAccessToken($this->operations)->getJson("/api/v1/admin/landing/applications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.duplicates.applications.0.reference', 'CAND-000002')
            ->assertJsonPath('data.prefill.phone_number', '0748123390')
            ->assertJsonPath('data.prefill.gender', 'M')
            ->assertJsonPath('data.prefill.trade_id', $this->trade('macon')->id)
            ->assertJsonPath('data.prefill.residence_commune_id', $this->commune())
            ->assertJsonPath('data.actions', ['review' => true, 'refuse' => true, 'convert' => true])
            ->assertJsonPath('data.files.identity.name', 'cni.jpg');

        $this->withAccessToken($this->operations)->postJson("/api/v1/admin/landing/applications/{$id}/review")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'en_verification');

        $this->withAccessToken($this->operations)->postJson('/api/v1/admin/workers', [
            'landing_application_id' => $id,
            'commercial_id' => $this->commercial->commercialProfile->id,
            'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'dial_code' => '225', 'phone_number' => '0748123390',
            'residence_commune_id' => $this->commune(), 'trade_id' => $this->trade('macon')->id, 'experience_years' => 8,
        ])->assertCreated();

        $application = LandingWorkerApplication::query()->findOrFail($id);
        $worker = Worker::query()->where('phone_number', '0748123390')->sole();
        $this->assertSame('convertie', $application->status->value);
        $this->assertSame($worker->id, $application->worker_id);
        $this->assertEqualsCanonicalizing(['worker_cni', 'worker_photo'], $worker->documents()->with('documentType')->get()->pluck('documentType.code')->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'landing.application_converted')->count());

        $this->withAccessToken($this->operations)->postJson("/api/v1/admin/landing/applications/{$id}/refuse", ['reason' => 'Pièce illisible.'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.application.0', 'Cette candidature est déjà traitée.');
    }

    public function test_a_contact_message_is_taken_over_then_closed_and_flagged_when_late(): void
    {
        $this->postJson('/api/v1/landing/contact-messages', [
            'profile' => 'Promoteur immobilier', 'name' => 'Jean-Marc Kouadio', 'company' => 'SCI Les Rôniers', 'phone' => '+225 07 22 18 40 11',
            'email' => 'jm@ronier.ci', 'subject' => 'Demande de démonstration', 'message' => 'Pouvez-vous nous présenter la plateforme ?', 'consent' => true,
        ], ['X-Landing-Key' => 'cle-du-site'])->assertCreated()->assertJsonPath('data.reference', 'MSG-000001');

        $this->travel(2)->days();
        $this->operations = $this->tokenFor(Admin::factory()->role('operations')->create());
        $support = $this->tokenFor(Admin::factory()->role('support')->create());

        $id = $this->withAccessToken($support)->getJson('/api/v1/admin/landing/messages?late=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.late', 1)
            ->assertJsonPath('data.0.late', true)
            ->json('data.0.id');

        $this->withAccessToken($support)->postJson("/api/v1/admin/landing/messages/{$id}/status", ['status' => 'en_cours'])->assertOk()->assertJsonPath('data.status.value', 'en_cours');
        $this->withAccessToken($support)->postJson("/api/v1/admin/landing/messages/{$id}/status", ['status' => 'traite', 'note' => 'Démonstration prévue lundi.'])
            ->assertOk()
            ->assertJsonPath('message', 'Message traité.')
            ->assertJsonPath('data.late', false)
            ->assertJsonPath('data.handling_note', 'Démonstration prévue lundi.');
        $this->withAccessToken($support)->postJson("/api/v1/admin/landing/messages/{$id}/status", ['status' => 'en_cours'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'Ce message est déjà traité.');

        $this->withAccessToken($support)->getJson('/api/v1/admin/landing/overview')->assertJsonPath('data.messages.traite', 1)->assertJsonPath('data.messages.late', 0);
    }

    public function test_rights_follow_the_existing_modules(): void
    {
        $finance = $this->tokenFor(Admin::factory()->role('finance')->create());

        $this->withAccessToken($finance)->getJson('/api/v1/admin/landing/messages')->assertForbidden();
        $this->withAccessToken($this->operations)->getJson('/api/v1/admin/landing/newsletter')->assertForbidden();
        $this->withAccessToken($finance)->postJson('/api/v1/admin/landing/applications/01a2002c-0000-7000-8000-000000000000/refuse', ['reason' => 'Pièce illisible.'])->assertForbidden();
    }

    private function apply(array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/landing/worker-applications', [
            'first_name' => 'Ibrahim', 'last_name' => 'Ouattara', 'phone' => '+225 07 48 12 33 90', 'gender' => 'Homme', 'birth_date' => '1992-03-14',
            'photo' => UploadedFile::fake()->image('photo.jpg'), 'identity_document' => UploadedFile::fake()->image('cni.jpg'),
            'trade' => 'Maçon', 'daily_rate' => 25000, 'experience_years' => 8, 'level' => 'Confirmé', 'skills' => ['Coffrage'],
            'commune' => 'Cocody', 'neighborhood' => 'Angré', 'mobility' => '10 km', 'availability' => 'Immédiate', 'available_days' => ['Lun', 'Mar'],
            'payout_method' => 'Orange Money', 'payout_number' => '0748123390', 'consent' => '1',
            ...$overrides,
        ], ['Accept' => 'application/json', 'X-Landing-Key' => 'cle-du-site']);
    }

    private function tokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }
}
