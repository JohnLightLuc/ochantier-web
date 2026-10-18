<?php

namespace Tests\Feature\Promoter;

use App\Enums\AvailabilityStatus;
use App\Enums\SkillLevel;
use App\Enums\WorkerVerificationStatus;
use App\Models\Commune;
use App\Models\Trade;
use App\Models\User;
use App\Models\Worker;
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

    /**
     * Ouvrier validé, au tarif net déclaré donné, dont le métier principal est $trade.
     */
    protected function worker(string $name, string $trade = 'macon', int $netRate = 22000, AvailabilityStatus $availability = AvailabilityStatus::Disponible, ?float $rating = null, WorkerVerificationStatus $status = WorkerVerificationStatus::Valide): Worker
    {
        static $phone = 1000000;
        $commercial = User::factory()->commercial()->create()->commercialProfile;
        [$first, $last] = explode(' ', $name, 2);

        $worker = Worker::query()->create([
            'reference' => 'OUV-T'.$phone,
            'origin_commercial_id' => $commercial->id,
            'current_commercial_id' => $commercial->id,
            'first_name' => $first,
            'last_name' => $last,
            'dial_code' => '225',
            'phone_number' => '07'.str_pad((string) $phone++, 8, '0', STR_PAD_LEFT),
            'residence_commune_id' => $this->commune(),
            'estimated_daily_rate' => $netRate,
            'verification_status' => $status,
            'availability_status' => $availability,
            'rating_avg' => $rating,
            'rating_count' => $rating ? 12 : 0,
        ]);
        $worker->workerTrades()->create(['trade_id' => $this->trade($trade)->id, 'is_primary' => true, 'years_experience' => 5, 'level' => SkillLevel::Confirme]);

        return $worker;
    }

    protected function trade(string $code = 'macon'): Trade
    {
        return Trade::query()->where('code', $code)->firstOrFail();
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
