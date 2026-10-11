<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Site;
use App\Models\Worker;
use App\Support\AttendanceCalculator as Calc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Journée de la veille de la date de référence, arrivée 07:30 et départ 17:00 validés, pause d’une heure, non clôturée.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Attendance::referenceDate()->subDay()->format('Y-m-d');
        $proofs = fn (string $k, string $t): array => [
            ['id' => "{$k}1", 'type' => Calc::PHOTO, 'file' => "photo-{$k}.jpg", 'at' => "{$date} {$t}", 'by' => 'SICOGI · Chef', 'status' => 'Reçue', 'hash' => "SHA-{$k}", 'version' => 1, 'comment' => null],
            ['id' => "{$k}2", 'type' => Calc::SIGNATURE, 'file' => "signature-{$k}.png", 'at' => "{$date} {$t}", 'by' => 'SICOGI · Chef', 'status' => 'Reçue', 'hash' => "SIG-{$k}", 'version' => 1, 'comment' => null],
        ];
        $location = ['meters' => 40, 'accuracy' => 10, 'status' => 'Dans la zone', 'at' => $date, 'source' => null];

        return [
            'reference' => 'PT-2026-'.fake()->unique()->numberBetween(5000, 9999),
            'date' => $date,
            'worker_id' => Worker::factory(),
            'site_id' => Site::factory(),
            'assignment_reference' => 'AFF-2026-0999',
            'planned_start' => '07:30',
            'planned_end' => '17:00',
            'night' => false,
            'arrival' => Attendance::event('Validée', ['time' => '07:30', 'by' => 'SICOGI · Chef', 'at' => "{$date} 07:30", 'validated_by' => 'SICOGI · Chef', 'validated_at' => "{$date} 07:33", 'location' => $location, 'proofs' => $proofs('a', '07:30')]),
            'departure' => Attendance::event('Validé', ['time' => '17:00', 'by' => 'SICOGI · Chef', 'at' => "{$date} 17:00", 'validated_by' => 'SICOGI · Chef', 'validated_at' => "{$date} 17:04", 'location' => $location, 'proofs' => $proofs('d', '17:00')]),
            'pauses' => [['type' => 'Pause déjeuner', 'start' => '12:00', 'end' => '13:00', 'minutes' => 60, 'validated' => true, 'by' => 'SICOGI · Chef']],
            'closure' => ['status' => ''],
            'transmission' => Attendance::NOT_TRANSMITTED,
            'notes' => [],
            'last_update' => "{$date} 17:04",
        ];
    }

    /**
     * Arrivée validée à l’heure donnée, sans départ.
     */
    public function arrivedOnly(string $time = '07:30'): static
    {
        return $this->state(fn (array $a): array => [
            'arrival' => [...$a['arrival'], 'time' => $time],
            'departure' => Attendance::event('Non enregistré'),
            'pauses' => [],
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['closure' => ['status' => 'Clôturée automatiquement', 'by' => 'Système', 'at' => '23:59']]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['date' => $date]);
    }
}
