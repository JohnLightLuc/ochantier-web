<?php

namespace Tests\Feature\Promoter;

use App\Models\Commune;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Base des tests de l’espace promoteur : référentiels, stockage simulé et promoteur connecté.
 */
abstract class PromoterTestCase extends AuthTestCase
{
    protected User $promoter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.media'));
        $this->seed([GeographySeeder::class, TradeSeeder::class, DocumentTypeSeeder::class]);

        $this->promoter = User::factory()->promoter()->create(['first_name' => null, 'last_name' => null, 'dial_code' => '225', 'phone_number' => '0712345634']);
        $this->actingAsPromoter($this->promoter);
    }

    /**
     * @return $this
     */
    protected function actingAsPromoter(User $user): static
    {
        return $this->withAccessToken($this->openSession($user)->accessToken);
    }

    protected function commune(string $name = 'Cocody'): string
    {
        return Commune::query()->where('name', $name)->value('id');
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
