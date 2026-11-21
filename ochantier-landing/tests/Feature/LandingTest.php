<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\WorkerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Site public : la page, et ses formulaires validés ici puis transmis à l’API O’CHANTIER avec la clé partagée.
 */
class LandingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://api.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API, 'services.ochantier_api.key' => 'cle-du-site']);
    }

    public function test_la_landing_est_servie_avec_son_template_intact(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="csrf-token"', false)
            ->assertSee('<x-dc>', false)
            ->assertSee('{{ formOpen }}', false)
            ->assertSee('carte-chantiers.html', false);
    }

    public function test_une_demande_de_contact_est_transmise_a_l_api(): void
    {
        Http::fake([self::API.'/landing/contact-messages' => Http::response(['message' => 'Message reçu.', 'data' => ['reference' => 'MSG-000001']], 201)]);

        $this->postJson('/contact', [
            'profil' => 'Promoteur immobilier',
            'nom' => 'Adama Ouattara',
            'tel' => '+225 07 00 00 00 00',
            'email' => 'adama@exemple.ci',
            'objet' => 'Demande de démonstration',
            'message' => 'Nous lançons une villa à Cocody.',
            'consent' => '1',
        ])->assertCreated()->assertExactJson(['reference' => 'MSG-000001']);

        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('X-Landing-Key', 'cle-du-site') && $r['name'] === 'Adama Ouattara' && $r['subject'] === 'Demande de démonstration' && $r['profile'] === 'Promoteur immobilier');
        $this->assertSame(0, ContactRequest::count());
    }

    public function test_le_contact_sans_consentement_est_refuse_sans_appeler_l_api(): void
    {
        Http::fake();

        $this->postJson('/contact', ['consent' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nom', 'tel', 'email', 'message', 'consent']);

        Http::assertNothingSent();
    }

    public function test_une_erreur_de_l_api_revient_sur_le_champ_du_formulaire(): void
    {
        Http::fake([self::API.'/landing/contact-messages' => Http::response(['message' => 'Erreur', 'errors' => ['subject' => ['L’objet est trop long.']]], 422)]);

        $this->postJson('/contact', ['profil' => 'Autre', 'nom' => 'A', 'tel' => '07', 'email' => 'a@b.ci', 'objet' => 'X', 'message' => 'Bonjour', 'consent' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['objet' => 'L’objet est trop long.']);
    }

    public function test_une_api_injoignable_invite_a_reessayer(): void
    {
        Http::fake([self::API.'/*' => Http::response(['message' => 'Erreur serveur'], 500)]);

        $this->postJson('/newsletter', ['email' => 'adama@exemple.ci'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Le service est momentanément indisponible. Réessayez dans quelques minutes.');
    }

    public function test_l_inscription_newsletter_est_transmise_en_minuscules(): void
    {
        Http::fake([self::API.'/landing/newsletter' => Http::response(['data' => ['subscribed' => true]], 201)]);

        $this->postJson('/newsletter', ['email' => 'Adama@Exemple.ci'])->assertCreated();

        Http::assertSent(fn (ClientRequest $r) => $r['email'] === 'adama@exemple.ci');
    }

    public function test_une_candidature_ouvrier_est_transmise_avec_ses_pieces(): void
    {
        Http::fake([self::API.'/landing/worker-applications' => Http::response(['data' => ['reference' => 'CAND-000001']], 201)]);

        $this->post('/ouvriers', [
            'prenom' => 'Adama',
            'nom' => 'Ouattara',
            'tel' => '+225 07 00 00 00 00',
            'cni' => UploadedFile::fake()->create('cni.pdf', 200, 'application/pdf'),
            'photo' => UploadedFile::fake()->image('photo.jpg'),
            'metier' => 'Maçon',
            'exp' => 5,
            'level' => 'Confirmé',
            'sec' => ['Coffrage', 'Ferraillage'],
            'commune' => 'Cocody',
            'radius' => '10 km',
            'dispo' => 'Immédiate',
            'days' => ['Lun', 'Mar'],
            'pay' => 'Orange Money',
            'chantier' => 'Résidence Les Palmiers',
            'consent' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->assertExactJson(['reference' => 'CAND-000001']);

        Http::assertSent(function (ClientRequest $r) {
            $parts = collect($r->data())->groupBy('name')->map(fn ($items) => $items->pluck('contents')->all());

            return $r->isMultipart()
                && $parts['first_name'] === ['Adama']
                && $parts['trade'] === ['Maçon']
                && $parts['skills[]'] === ['Coffrage', 'Ferraillage']
                && $parts['target_site'] === ['Résidence Les Palmiers']
                && isset($parts['identity_document'], $parts['photo']);
        });
        $this->assertSame(0, WorkerApplication::count());
    }

    public function test_la_piece_d_identite_est_obligatoire(): void
    {
        $this->postJson('/ouvriers', ['prenom' => 'Adama'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cni', 'metier', 'commune', 'consent']);
    }

    public function test_les_demandes_deja_enregistrees_sont_transmises_une_fois(): void
    {
        Storage::fake('local');
        Http::fake([
            self::API.'/landing/contact-messages' => Http::response(['data' => ['reference' => 'MSG-000009']], 201),
            self::API.'/landing/worker-applications' => Http::response(['data' => ['reference' => 'CAND-000009']], 201),
        ]);
        ContactRequest::create(['profil' => 'Autre', 'nom' => 'Awa', 'telephone' => '0700000000', 'email' => 'awa@b.ci', 'objet' => 'Info', 'message' => 'Bonjour']);
        Storage::disk('local')->put('ouvriers/cni/cni.jpg', 'image');
        WorkerApplication::create(['prenom' => 'Issa', 'nom' => 'Koné', 'telephone' => '0712909504', 'cni_path' => 'ouvriers/cni/cni.jpg', 'metier' => 'Électricien', 'experience_annees' => 6, 'niveau' => 'Confirmé', 'commune' => 'Abobo', 'rayon_deplacement' => '5 km', 'disponibilite' => 'Immédiate', 'moyen_paiement' => 'Wave']);

        $this->artisan('landing:transmettre')->assertSuccessful();
        $this->artisan('landing:transmettre')->assertSuccessful();

        $this->assertSame('MSG-000009', ContactRequest::sole()->reference_api);
        $this->assertSame('CAND-000009', WorkerApplication::sole()->reference_api);
        Http::assertSentCount(2);
    }
}
