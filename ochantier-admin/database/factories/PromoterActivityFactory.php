<?php

namespace Database\Factories;

use App\Enums\ActivityAuthorKind;
use App\Models\Promoter;
use App\Models\PromoterActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoterActivity>
 */
class PromoterActivityFactory extends Factory
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
            'occurred_at' => now(),
            'author' => 'N. Yao',
            'author_kind' => ActivityAuthorKind::Admin,
            'action' => 'a modifié le profil',
            'target' => null,
            'icon' => 'edit',
            'tone' => 'purple',
            'link' => null,
        ];
    }
}
