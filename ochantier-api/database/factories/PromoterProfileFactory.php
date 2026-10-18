<?php

namespace Database\Factories;

use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Models\PromoterProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoterProfile>
 */
class PromoterProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'profile_kind' => PromoterProfileKind::Particulier,
            'verification_status' => PromoterVerificationStatus::Brouillon,
        ];
    }
}
