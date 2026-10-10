<?php

namespace Database\Factories;

use App\Enums\WorkerActivityCategory;
use App\Models\Worker;
use App\Models\WorkerActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerActivity>
 */
class WorkerActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'worker_id' => Worker::factory(),
            'occurred_at' => now(),
            'author' => 'N. Yao',
            'category' => WorkerActivityCategory::Update,
            'action' => 'a modifié le profil',
            'origin' => 'Back-office',
        ];
    }
}
