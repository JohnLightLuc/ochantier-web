<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\CommercialProfile;
use App\Models\PromoterProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_type' => UserType::Promoter,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'dial_code' => '225',
            'phone_number' => '07'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'status' => UserStatus::Actif,
        ];
    }

    /**
     * Promoteur actif avec son profil (particulier par défaut).
     */
    public function promoter(): static
    {
        return $this->state(['user_type' => UserType::Promoter])
            ->afterCreating(fn (User $user) => PromoterProfile::factory()->for($user)->create());
    }

    /**
     * Commercial avec son profil ; mot de passe « motdepasse1 » par défaut.
     */
    public function commercial(string $password = 'motdepasse1'): static
    {
        return $this->state(['user_type' => UserType::Commercial, 'password_hash' => $password])
            ->afterCreating(fn (User $user) => CommercialProfile::factory()->for($user)->create());
    }

    /**
     * Compte créé par le Back Office avec un mot de passe provisoire, jamais encore utilisé.
     */
    public function provisional(): static
    {
        return $this->state(['status' => UserStatus::EnAttenteActivation, 'must_change_password' => true, 'phone_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => UserStatus::Suspendu, 'suspended_at' => now()]);
    }
}
