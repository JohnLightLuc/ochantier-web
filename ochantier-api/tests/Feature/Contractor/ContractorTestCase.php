<?php

namespace Tests\Feature\Contractor;

use App\Models\City;
use App\Models\Trade;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Base des tests de l’application Entrepreneurs et Techniciens : référentiels, stockage simulé et entrepreneur
 * connecté, sans profil choisi.
 */
abstract class ContractorTestCase extends AuthTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media'));
        $this->seed([GeographySeeder::class, TradeSeeder::class, DocumentTypeSeeder::class]);

        $this->user = User::factory()->contractor()->create(['first_name' => null, 'last_name' => null, 'dial_code' => '225', 'phone_number' => '0712345678', 'email' => null]);
        $this->actingAsContractor($this->user);
    }

    /**
     * @return $this
     */
    protected function actingAsContractor(User $user): static
    {
        return $this->withAccessToken($this->openSession($user)->accessToken);
    }

    protected function city(string $name = 'Abidjan'): string
    {
        return City::query()->where('name', $name)->value('id');
    }

    protected function trade(string $code = 'electricien'): string
    {
        return Trade::query()->where('code', $code)->value('id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function upload(array $data): TestResponse
    {
        return $this->post('/api/v1/contractor/profile/documents', $data, ['Accept' => 'application/json']);
    }

    /**
     * Dossier d’indépendant complet, prêt à être soumis.
     */
    protected function completeIndependent(): void
    {
        $this->postJson('/api/v1/contractor/profile', [
            'kind' => 'independant',
            'last_name' => 'Koffi',
            'first_name' => 'Yao Serge',
            'birth_date' => '1990-04-12',
            'city_id' => $this->city(),
            'address' => 'Cocody, Riviera 3',
            'trade_ids' => [$this->trade('electricien'), $this->trade('plombier')],
            'experience_years' => 8,
        ])->assertCreated();

        $this->upload(['document_type' => 'contractor_cni', 'number' => 'CI-DEMO-000123', 'recto' => $this->image('recto.jpg'), 'verso' => $this->image('verso.jpg')])->assertCreated();
    }

    /**
     * Dossier d’indépendant soumis au Back Office.
     */
    protected function submitIndependent(): void
    {
        $this->completeIndependent();
        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])->assertOk();
    }

    protected function image(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    protected function pdf(string $name = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }
}
