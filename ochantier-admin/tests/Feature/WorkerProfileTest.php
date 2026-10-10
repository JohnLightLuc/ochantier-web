<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\WorkerStatus;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkerSeeder::class);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Ali',
            'last_name' => 'Konaté',
            'phone' => '+225 07 99 88 77 66',
            'trade' => 'Maçon',
            'secondary_trades' => ['Coffreur'],
            'specialties' => 'Chapes, Enduits',
            'skills' => 'Lecture de plans',
            'experience_years' => '4',
            'daily_rate' => '9 500',
            'availability_choice' => 'Disponible',
            'zones' => ['Cocody'],
            'works_solo' => '1',
            'works_in_team' => '1',
            'accepts_contracts' => '0',
            'experiences' => [
                ['company' => 'SICOGI', 'mission' => 'Gros œuvre', 'trade' => 'Maçon', 'started_at' => '2025-01-06', 'ended_at' => '2025-06-30'],
                ['company' => '', 'mission' => '', 'started_at' => ''],
            ],
            ...$overrides,
        ];
    }

    public function test_every_tab_renders(): void
    {
        foreach (['', '/identite-documents', '/metiers-competences', '/disponibilites', '/affectations', '/pointages', '/paiements', '/avis', '/remplacements', '/historique'] as $tab) {
            $this->get("/ouvriers/siaka-bamba{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Workers/Show'));
        }
        $this->get('/ouvriers/siaka-bamba/inconnu')->assertNotFound();
    }

    public function test_overview_has_banner_kpis_and_alerts(): void
    {
        $this->get('/ouvriers/siaka-bamba')->assertInertia(fn (Assert $page) => $page
            ->where('banner.title', 'Profil suspendu.')
            ->has('kpis', 4)
            ->where('tabCounts.remplacements', 1)
            ->where('alerts.0.title', 'Document expiré')
            ->where('commissionRate', 10)
        );
    }

    public function test_replacement_tab_lists_candidates_for_the_open_case(): void
    {
        $this->get('/ouvriers/siaka-bamba/remplacements')->assertInertia(fn (Assert $page) => $page->has('candidates', 3)->where('candidates.0.slug', 'koffi-adjoumani'));
    }

    public function test_worker_is_created_with_documents_and_experiences(): void
    {
        Storage::fake();

        $this->post('/ouvriers', $this->payload(['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')]))
            ->assertRedirect('/ouvriers/ali-konate');

        $worker = Worker::firstWhere('slug', 'ali-konate');
        $this->assertSame(WorkerStatus::ToReview, $worker->status);
        $this->assertSame('OUV-0332', $worker->code);
        $this->assertSame(9500, $worker->daily_rate);
        $this->assertSame(['Chapes', 'Enduits'], $worker->specialties);
        $this->assertSame(1, $worker->experiences()->count());
        $this->assertSame(DocumentStatus::ToReview, $worker->documents()->where('type', 'cv')->first()->status);
        $this->assertSame(5, $worker->documents()->where('status', DocumentStatus::Missing)->count());
        $this->assertSame('a créé le profil', $worker->activities()->first()->action);
    }

    public function test_creation_is_validated(): void
    {
        $koffi = Worker::firstWhere('slug', 'koffi-adjoumani');

        $this->post('/ouvriers', $this->payload(['first_name' => '', 'phone' => $koffi->phone, 'daily_rate' => '500', 'birth_date' => now()->subYears(16)->format('Y-m-d')]))
            ->assertSessionHasErrors([
                'first_name' => 'Ce champ est obligatoire.',
                'phone' => 'Doublon potentiel : numéro déjà utilisé par Koffi Adjoumani (OUV-0128).',
                'daily_rate' => 'Montant entre 3 000 et 100 000 FCFA.',
                'birth_date' => 'L’ouvrier doit avoir au moins 18 ans.',
            ]);
    }

    public function test_worker_can_be_updated_without_flagging_its_own_phone(): void
    {
        $koffi = Worker::firstWhere('slug', 'koffi-adjoumani');

        $this->get('/ouvriers/koffi-adjoumani/modifier/2')->assertInertia(fn (Assert $page) => $page->where('step', 1)->where('values.trade', 'Maçon'));
        $this->post('/ouvriers/koffi-adjoumani', $this->payload(['first_name' => 'Koffi', 'last_name' => 'Adjoumani', 'phone' => $koffi->phone, 'daily_rate' => '12500']))
            ->assertRedirect('/ouvriers/koffi-adjoumani');

        $this->assertSame(12500, $koffi->fresh()->daily_rate);
        $this->assertSame(WorkerStatus::Validated, $koffi->fresh()->status);
    }

    public function test_archived_profile_cannot_be_edited(): void
    {
        $this->get('/ouvriers/lacina-coulibaly/modifier')->assertForbidden();
    }

    public function test_skills_and_experiences_can_be_managed(): void
    {
        $this->post('/ouvriers/koffi-adjoumani/listes/competences', ['value' => 'Ferraillage'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/listes/competences', ['value' => 'ferraillage'])->assertSessionHasErrors(['value' => 'Cette valeur existe déjà.']);
        $this->delete('/ouvriers/koffi-adjoumani/listes/competences', ['value' => 'Dosage béton'])->assertRedirect();
        $this->post('/ouvriers/koffi-adjoumani/listes/metiers-secondaires', ['value' => 'Maçon'])->assertSessionHasErrors('value');

        $koffi = Worker::firstWhere('slug', 'koffi-adjoumani');
        $this->assertContains('Ferraillage', $koffi->skills);
        $this->assertNotContains('Dosage béton', $koffi->skills);

        $this->post('/ouvriers/koffi-adjoumani/experiences', ['company' => 'SAPH', 'mission' => 'Lot B', 'trade' => 'Maçon', 'started_at' => '2024-01-01', 'ended_at' => '2023-01-01'])->assertSessionHasErrors('ended_at');
        $this->post('/ouvriers/koffi-adjoumani/experiences', ['company' => 'SAPH', 'mission' => 'Lot B', 'trade' => 'Maçon', 'started_at' => '2024-01-01'])->assertRedirect();
        $this->assertSame(3, $koffi->experiences()->count());

        $experience = $koffi->experiences()->first();
        $this->delete("/ouvriers/koffi-adjoumani/experiences/{$experience->id}")->assertRedirect();
        $this->assertSame(2, $koffi->experiences()->count());
    }
}
