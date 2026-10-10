<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Promoter;
use App\Models\PromoterDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoterDocument>
 */
class PromoterDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promoter_id' => Promoter::factory(),
            'type' => DocumentType::TaxCertificate,
            'reference' => 'AF-'.fake()->numerify('####-#####'),
            'added_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => DocumentStatus::ToReview,
        ];
    }
}
