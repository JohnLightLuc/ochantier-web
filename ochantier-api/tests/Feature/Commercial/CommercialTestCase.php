<?php

namespace Tests\Feature\Commercial;

use App\Models\Commune;
use App\Models\Trade;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Base des tests de l’espace commercial : référentiels, stockage simulé et commercial connecté.
 */
abstract class CommercialTestCase extends AuthTestCase
{
    protected User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media'));
        $this->seed([GeographySeeder::class, TradeSeeder::class, DocumentTypeSeeder::class]);

        $this->commercial = User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0500000001']);
        $this->actingAsCommercial($this->commercial);
    }

    /**
     * @return $this
     */
    protected function actingAsCommercial(User $user): static
    {
        return $this->withAccessToken($this->openSession($user)->accessToken);
    }

    protected function commune(string $name = 'Cocody'): string
    {
        return Commune::query()->where('name', $name)->value('id');
    }

    protected function trade(string $code = 'macon'): Trade
    {
        return Trade::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function contact(array $overrides = []): array
    {
        return [
            'first_name' => 'Jean',
            'last_name' => 'Kouassi',
            'dial_code' => '225',
            'phone_number' => '07 12 34 56 78',
            'commune_id' => $this->commune(),
            'trade_id' => $this->trade()->id,
            'contact_consent' => true,
            ...$overrides,
        ];
    }

    /**
     * Dossier complet hors pièces : identité, activité et contact d’urgence.
     *
     * @return array<string, mixed>
     */
    protected function dossier(array $overrides = []): array
    {
        return [
            'gender' => 'M',
            'birth_date' => '1991-04-12',
            'birth_place' => 'Bouaké',
            'experience_years' => 6,
            'daily_rate' => 15000,
            'emergency_contact_name' => 'Awa Koffi',
            'emergency_contact_dial_code' => '225',
            'emergency_contact_phone_number' => '05 98 76 54 32',
            ...$overrides,
        ];
    }

    protected function createProspect(array $overrides = []): string
    {
        return $this->postJson('/api/v1/commercial/prospects', $this->contact($overrides))->assertCreated()->json('data.id');
    }

    protected function uploadIdentity(string $prospectId, string $number = 'CI0012345678'): void
    {
        $this->post("/api/v1/commercial/prospects/{$prospectId}/documents", [
            'document_type' => 'worker_cni',
            'number' => $number,
            'recto' => UploadedFile::fake()->image('cni-recto.jpg'),
            'verso' => UploadedFile::fake()->image('cni-verso.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    protected function uploadPhoto(string $prospectId): void
    {
        $this->post("/api/v1/commercial/prospects/{$prospectId}/documents", [
            'document_type' => 'worker_photo',
            'file' => UploadedFile::fake()->image('photo.jpg', 400, 400),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    /**
     * Prospect au dossier complet, prêt à être soumis.
     */
    protected function readyProspect(array $contact = []): string
    {
        $id = $this->createProspect($contact);
        $this->patchJson("/api/v1/commercial/prospects/{$id}", $this->dossier())->assertOk();
        $this->uploadIdentity($id);
        $this->uploadPhoto($id);

        return $id;
    }
}
