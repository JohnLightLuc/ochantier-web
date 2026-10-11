<?php

namespace Database\Factories;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use App\Models\Need;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Need>
 */
class NeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = sprintf('%04d', fake()->unique()->numberBetween(500, 999));

        return [
            'slug' => "bes-2026-{$number}",
            'reference' => "BES-2026-{$number}",
            'site_id' => Site::factory(),
            'urgency' => NeedUrgency::Normal,
            'type' => 'Besoin hebdomadaire',
            'source' => 'Application Promoteur',
            'admin' => 'N. Yao',
            'district' => 'Riviera 3',
            'contact' => 'Ing. Kouadio Brou',
            'contact_phone' => '0711223344',
            'starts_on' => '2026-10-13',
            'ends_on' => '2026-11-12',
            'working_hours' => '07:30 – 17:00 · pause 12:00 – 13:00',
            'working_days' => 'Lun, Mar, Mer, Jeu, Ven, Sam',
            'requirements' => ['Pièce d’identité', 'Profil vérifié'],
            'anomalies' => [],
            'created_on' => '2026-09-15',
            'updated_on' => '2026-09-15',
        ];
    }

    /**
     * Une ligne (et son offre publiée) avec des ouvriers déjà trouvés.
     */
    public function withLine(string $trade = 'Maçon', int $quantity = 4, int $found = 0, string $deadline = '2026-10-05', NeedAssignmentStatus $status = NeedAssignmentStatus::ToContact): static
    {
        return $this->afterCreating(function (Need $need) use ($trade, $quantity, $found, $deadline, $status): void {
            $position = $need->lines()->count() + 1;
            $line = $need->lines()->create([
                'position' => $position, 'trade_group' => 'Gros œuvre', 'trade' => $trade, 'specialty' => null, 'quantity' => $quantity,
                'experience' => 'Expérimenté', 'daily_cost' => 12000, 'pay_type' => 'Journalier', 'skills' => [], 'certifications' => [],
            ]);
            $offer = $need->offers()->create([
                'need_line_id' => $line->id, 'reference' => "{$need->reference}/{$position}", 'title' => "{$trade}s", 'urgency' => $need->urgency,
                'visibility' => 'Publique', 'published_on' => $need->created_on, 'deadline' => $deadline, 'starts_on' => $need->starts_on, 'ends_on' => $need->ends_on,
                'applications_open' => true,
            ]);
            for ($i = 0; $i < $found; $i++) {
                $offer->assignments()->create([
                    'need_id' => $need->id, 'reference' => "AFF-{$need->id}-{$position}-{$i}", 'worker_name' => "Ouvrier {$i}", 'trade' => $trade,
                    'daily_cost' => 12000, 'status' => $status, 'source' => 'Manuel', 'selected_on' => '2026-09-20',
                ]);
            }
        });
    }

    public function manual(NeedManualStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['manual_status' => $status, 'reason' => 'Motif de démonstration']);
    }
}
