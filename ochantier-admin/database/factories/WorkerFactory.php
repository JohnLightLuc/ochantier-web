<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Worker>
 */
class WorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'slug' => Str::slug("{$first} {$last}").'-'.fake()->unique()->numberBetween(1, 99999),
            'code' => sprintf('OUV-%04d', fake()->unique()->numberBetween(1000, 9999)),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'Homme',
            'nationality' => 'Ivoirienne',
            'phone' => '+225 07 '.fake()->unique()->numerify('## ## ## ##'),
            'commune' => 'Cocody',
            'city' => 'Abidjan',
            'country' => 'Côte d’Ivoire',
            'trade' => 'Maçon',
            'secondary_trades' => [],
            'specialties' => ['Maçonnerie traditionnelle'],
            'skills' => ['Lecture de plans', 'Dosage béton'],
            'certifications' => [],
            'qualification' => 'Ouvrier qualifié',
            'experience_years' => 5,
            'daily_rate' => 10000,
            'zones' => ['Cocody'],
            'works_solo' => true,
            'works_in_team' => true,
            'accepts_contracts' => true,
            'recruiter' => 'Adama Coulibaly',
            'status' => WorkerStatus::Validated,
            'availability' => WorkerAvailability::Available,
            'rating' => 4.5,
            'reviews_count' => 10,
            'registered_at' => now()->subYear(),
            'demo_relations' => ['assignments' => [], 'timesheets' => [], 'payouts' => [], 'reviews' => [], 'replacements' => [], 'stats' => ['missions' => 0, 'days' => 0, 'presence' => 0, 'acceptance' => 0, 'cancellation' => 0]],
        ];
    }

    public function toReview(): static
    {
        return $this->state(['status' => WorkerStatus::ToReview]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => WorkerStatus::Suspended, 'availability' => WorkerAvailability::Unavailable, 'previous_availability' => WorkerAvailability::Available, 'suspension_reason' => 'Absences répétées — trois absences non justifiées.']);
    }

    /**
     * Crée les six pièces justificatives avec le même statut.
     */
    public function withDocuments(DocumentStatus $status = DocumentStatus::Verified): static
    {
        return $this->afterCreating(function (Worker $worker) use ($status): void {
            foreach (WorkerDocumentType::cases() as $type) {
                $missing = $status === DocumentStatus::Missing;
                $worker->documents()->create([
                    'type' => $type,
                    'status' => $status,
                    'reference' => $missing ? null : 'REF-'.$type->value,
                    'file_name' => $missing ? null : $type->value.'.pdf',
                    'added_at' => $missing ? null : now(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $relations
     */
    public function withDemo(array $relations): static
    {
        return $this->state(fn (array $attributes): array => ['demo_relations' => [...$attributes['demo_relations'], ...$relations]]);
    }
}
