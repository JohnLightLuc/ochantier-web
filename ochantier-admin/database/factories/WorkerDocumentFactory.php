<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\WorkerDocumentType;
use App\Models\Worker;
use App\Models\WorkerDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerDocument>
 */
class WorkerDocumentFactory extends Factory
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
            'type' => WorkerDocumentType::Cv,
            'reference' => 'CV',
            'file_name' => 'cv.pdf',
            'added_at' => now(),
            'status' => DocumentStatus::ToReview,
        ];
    }
}
