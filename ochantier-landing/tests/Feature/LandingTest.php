<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\NewsletterSubscriber;
use App\Models\WorkerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_landing_est_servie_avec_son_template_intact(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="csrf-token"', false)
            ->assertSee('<x-dc>', false)
            ->assertSee('{{ formOpen }}', false)
            ->assertSee('carte-chantiers.html', false);
    }

    public function test_une_demande_de_contact_est_enregistree(): void
    {
        $this->postJson('/contact', [
            'profil' => 'Promoteur immobilier',
            'nom' => 'Adama Ouattara',
            'tel' => '+225 07 00 00 00 00',
            'email' => 'adama@exemple.ci',
            'objet' => 'Demande de démonstration',
            'message' => 'Nous lançons une villa à Cocody.',
            'consent' => '1',
        ])->assertCreated()->assertJsonStructure(['reference']);

        $this->assertStringStartsWith('OC-', ContactRequest::sole()->reference);
    }

    public function test_le_contact_sans_consentement_est_refuse(): void
    {
        $this->postJson('/contact', ['consent' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nom', 'tel', 'email', 'message', 'consent']);
    }

    public function test_l_inscription_newsletter_est_idempotente(): void
    {
        $this->postJson('/newsletter', ['email' => 'Adama@Exemple.ci'])->assertCreated();
        $this->postJson('/newsletter', ['email' => 'adama@exemple.ci'])->assertCreated();

        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_une_candidature_ouvrier_est_enregistree_avec_ses_pieces(): void
    {
        Storage::fake('local');

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
        ], ['Accept' => 'application/json'])->assertCreated();

        $application = WorkerApplication::sole();
        $this->assertSame(['Coffrage', 'Ferraillage'], $application->competences);
        Storage::disk('local')->assertExists([$application->cni_path, $application->photo_path]);
    }

    public function test_la_piece_d_identite_est_obligatoire(): void
    {
        $this->postJson('/ouvriers', ['prenom' => 'Adama'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cni', 'metier', 'commune', 'consent']);
    }
}
