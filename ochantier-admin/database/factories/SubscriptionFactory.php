<?php

namespace Database\Factories;

use App\Models\Promoter;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Abonnement Équipe actif, échéance dans 15 jours.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promoter_id' => Promoter::factory(),
            'plan_key' => 'equipe',
            'status' => 'Actif',
            'since' => '2026-03-01',
            'next_due' => '2026-10-08',
            'payment_method' => 'Orange Money',
        ];
    }

    /**
     * Ajoute le propriétaire et des collaborateurs.
     */
    public function withUsers(int $collaborators = 1): static
    {
        return $this->afterCreating(function (Subscription $s) use ($collaborators): void {
            $s->users()->create(['name' => 'Propriétaire', 'role' => 'Propriétaire', 'via' => 'Compte principal', 'status' => 'Actif', 'owner' => true]);
            for ($i = 0; $i < $collaborators; $i++) {
                $s->users()->create(['name' => "Collaborateur {$i}", 'role' => 'Technicien', 'via' => 'Invité par SMS', 'status' => 'Actif']);
            }
        });
    }
}
