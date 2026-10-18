<?php

namespace Database\Factories;

use App\Enums\CommercialProfileStatus;
use App\Enums\CommercialProfileVerificationStatus;
use App\Models\CommercialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialProfile>
 */
class CommercialProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['user_type' => 'commercial']),
            'employee_number' => 'COM-'.fake()->unique()->numerify('#####'),
            'verification_status' => CommercialProfileVerificationStatus::Valide,
            'status' => CommercialProfileStatus::Actif,
            'started_at' => now()->subMonths(3),
        ];
    }

    /**
     * Contrat commercial terminé : l’accès à l’application est fermé.
     */
    public function ended(): static
    {
        return $this->state(['status' => CommercialProfileStatus::Termine, 'ended_at' => now()]);
    }
}
