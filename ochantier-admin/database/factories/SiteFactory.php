<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\SiteDocumentType;
use App\Enums\SitePriority;
use App\Enums\SiteStatus;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Résidence '.fake()->unique()->lastName();
        $number = fake()->unique()->numberBetween(200, 999);

        return [
            'slug' => Str::slug($name).'-'.$number,
            'reference' => sprintf('CH-2026-%04d', $number),
            'name' => $name,
            'promoter_id' => null,
            'promoter_name' => 'Ivoire Properties',
            'commune' => 'Cocody',
            'city' => 'Abidjan',
            'address' => 'Boulevard Latrille, Cocody',
            'latitude' => 5.36,
            'longitude' => -3.97,
            'type' => 'Résidence',
            'category' => 'Construction neuve',
            'manager' => 'Ing. Kouadio Brou',
            'manager_phone' => '+225 07 11 22 33 44',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-12-18',
            'planned_days' => Site::workingDaysBetween(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-12-18')),
            'progress' => 55,
            'required_workers' => 20,
            'assigned_workers' => 20,
            'present_workers' => 18,
            'status' => SiteStatus::InProgress,
            'priority' => SitePriority::Normal,
            'description' => 'Construction de logements.',
            'working_hours' => '07:30 – 17:00',
            'working_days' => 'Lundi au samedi',
            'instructions' => 'Port des EPI obligatoire.',
            'access_conditions' => 'Accès par le portail principal.',
            'safety_equipment' => 'Casque, chaussures de sécurité',
            'budget' => 400_000_000,
            'engaged_amount' => 280_000_000,
            'paid_amount' => 190_000_000,
            'photos' => 3,
            'submitted_on' => '2026-05-02',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SiteStatus::Draft,
            'starts_on' => '2026-10-19',
            'ends_on' => '2027-04-30',
            'progress' => 0,
            'assigned_workers' => 0,
            'present_workers' => 0,
        ]);
    }

    public function status(SiteStatus $status, int $progress = 55): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status, 'progress' => $progress]);
    }

    /**
     * Six pièces vérifiées, sauf le PV de réception (statut au choix).
     */
    public function withDocuments(DocumentStatus $acceptanceReport = DocumentStatus::Missing): static
    {
        return $this->afterCreating(function (Site $site) use ($acceptanceReport): void {
            foreach (SiteDocumentType::cases() as $type) {
                $status = $type === SiteDocumentType::AcceptanceReport ? $acceptanceReport : DocumentStatus::Verified;
                $site->documents()->create([
                    'type' => $type,
                    'status' => $status,
                    'reference' => $status === DocumentStatus::Missing ? null : 'REF-2026001',
                    'file_name' => $status === DocumentStatus::Missing ? null : 'piece.pdf',
                    'added_at' => $status === DocumentStatus::Missing ? null : '2026-05-02',
                ]);
            }
        });
    }
}
