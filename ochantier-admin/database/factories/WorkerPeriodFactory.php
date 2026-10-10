<?php

namespace Database\Factories;

use App\Enums\AvailabilityType;
use App\Models\Worker;
use App\Models\WorkerPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerPeriod>
 */
class WorkerPeriodFactory extends Factory
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
            'starts_on' => now()->addDays(3),
            'ends_on' => now()->addDays(5),
            'type' => AvailabilityType::Unavailable,
            'reason' => 'Maladie',
        ];
    }
}
