<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\InvitationStatus;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentDocumentType;
use App\Enums\SalesAgentStatus;
use App\Models\SalesAgent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalesAgent>
 */
class SalesAgentFactory extends Factory
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
        $number = fake()->unique()->numberBetween(100, 999);

        return [
            'slug' => Str::slug("{$first} {$last}").'-'.$number,
            'code' => sprintf('COM-%03d', $number),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'Femme',
            'nationality' => 'Ivoirienne',
            'phone' => '+225 07 '.fake()->unique()->numerify('## ## ## ##'),
            'email' => Str::slug("{$first}.{$last}", '.').$number.'@ochantier.ci',
            'commune' => 'Cocody',
            'city' => 'Abidjan',
            'type' => 'Commercial salarié',
            'job_title' => 'Chargé de recrutement terrain',
            'agency' => 'Agence Cocody',
            'zone' => 'Cocody',
            'secondary_zones' => ['Plateau'],
            'communes' => ['Cocody', 'Plateau'],
            'priority_trades' => ['Maçon', 'Électricien'],
            'specialties' => ['Maçon'],
            'supervisor' => 'Fatou Diarra',
            'started_at' => '2024-01-08',
            'contract' => 'CDI',
            'weekly_target' => 4,
            'monthly_target' => 15,
            'status' => SalesAgentStatus::Active,
            'invitation_status' => InvitationStatus::Activated,
            'availability' => SalesAgentAvailability::Available,
            'recruited_total' => 180,
            'pay' => SalesAgent::defaultPay('Commercial salarié', 'CDI', '2024-01-08'),
            'access' => [...SalesAgent::defaultAccess('Commercial salarié', 'Cocody'), 'activated_at' => '2024-01-09', 'sessions' => [['id' => 's1', 'device' => 'Application Commercial · Android', 'location' => 'Cocody, Abidjan', 'ip' => '102.67.1.1', 'last' => 'Aujourd’hui · 08:40']]],
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SalesAgentStatus::Invited,
            'invitation_status' => InvitationStatus::Sent,
            'recruited_total' => 0,
            'started_at' => '2026-09-19',
            'access' => [...SalesAgent::defaultAccess('Commercial salarié', 'Cocody'), 'sent_at' => '2026-09-19', 'expires_at' => '2026-09-25'],
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SalesAgentStatus::Draft,
            'invitation_status' => InvitationStatus::NotSent,
            'recruited_total' => 0,
            'started_at' => '2026-09-22',
            'access' => SalesAgent::defaultAccess('Commercial salarié', 'Cocody'),
        ]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status' => SalesAgentStatus::Suspended,
            'availability' => SalesAgentAvailability::Unavailable,
            'previous_availability' => SalesAgentAvailability::Available,
            'suspension_reason' => 'Profils ouvriers dupliqués détectés.',
        ]);
    }

    /**
     * Crée les six pièces du dossier avec le même statut.
     */
    public function withDocuments(DocumentStatus $status = DocumentStatus::Verified): static
    {
        return $this->afterCreating(function (SalesAgent $agent) use ($status): void {
            foreach (SalesAgentDocumentType::cases() as $type) {
                $missing = $status === DocumentStatus::Missing;
                $agent->documents()->create([
                    'type' => $type,
                    'reference' => $missing ? null : 'REF-'.$type->value,
                    'file_name' => $missing ? null : $type->value.'.pdf',
                    'added_at' => $missing ? null : '2024-01-08',
                    'status' => $status,
                ]);
            }
        });
    }
}
