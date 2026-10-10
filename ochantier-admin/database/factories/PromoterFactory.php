<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Models\Promoter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promoter>
 */
class PromoterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'legal_form' => fake()->randomElement(['SA', 'SARL', 'SAS']),
            'rccm' => 'CI-ABJ-'.fake()->numberBetween(2000, 2026).'-B-'.fake()->numerify('#####'),
            'ncc' => fake()->numerify('#######').' A',
            'sector' => 'Promotion immobilière',
            'head_office' => fake()->streetAddress().', Abidjan',
            'representative' => fake()->name(),
            'representative_role' => 'Gérant',
            'phone' => '+225 07 '.fake()->numerify('## ## ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'account_type' => AccountType::Company,
            'location' => 'Abidjan, Cocody',
            'status' => PromoterStatus::Active,
            'payment_status' => PromoterPaymentStatus::UpToDate,
            'registered_at' => fake()->dateTimeBetween('-2 years', '-1 month'),
            'last_active_at' => now(),
            'demo_relations' => ['sites' => [], 'offers' => [], 'payments' => [], 'reviews' => [], 'disputes' => []],
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => PromoterStatus::Pending, 'payment_status' => PromoterPaymentStatus::Pending]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status' => PromoterStatus::Suspended,
            'payment_status' => PromoterPaymentStatus::Late,
            'suspension_reason' => 'Retards de paiement — échéances impayées.',
            'suspended_at' => now(),
        ]);
    }

    /**
     * Crée les cinq pièces KYC avec le même statut.
     */
    public function withDocuments(DocumentStatus $status = DocumentStatus::Verified): static
    {
        return $this->afterCreating(function (Promoter $promoter) use ($status): void {
            foreach (DocumentType::cases() as $type) {
                $promoter->documents()->create([
                    'type' => $type,
                    'status' => $status,
                    'reference' => $status === DocumentStatus::Missing ? null : 'REF-'.$type->value,
                    'added_at' => $status === DocumentStatus::Missing ? null : now(),
                ]);
            }
        });
    }
}
