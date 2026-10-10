<?php

namespace Database\Factories;

use App\Models\Worker;
use App\Models\WorkerExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerExperience>
 */
class WorkerExperienceFactory extends Factory
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
            'company' => fake()->company(),
            'mission' => 'Gros œuvre R+4',
            'trade' => 'Maçon',
            'started_at' => now()->subYear(),
            'ended_at' => now()->subMonths(6),
        ];
    }
}
