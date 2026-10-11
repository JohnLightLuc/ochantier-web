<?php

namespace Database\Factories;

use App\Enums\WorkerStatus;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Paiement hebdomadaire de 5 jours à 10 000 FCFA, validé et payé par Orange Money, reversement à préparer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'OP-2026-'.fake()->unique()->numberBetween(70000, 99999),
            'date' => '2026-09-19',
            'type' => 'Paiement hebdomadaire',
            'worker_id' => Worker::factory()->state(['status' => WorkerStatus::Validated, 'daily_rate' => 10000]),
            'site_id' => Site::factory(),
            'assignment_reference' => 'AFF-2026-'.fake()->unique()->numberBetween(5000, 9999),
            'period_start' => '2026-09-14',
            'period_end' => '2026-09-19',
            'days' => 5,
            'daily_rate' => 10000,
            'source' => 'Pointages clôturés',
            'method' => 'Orange Money',
            'provider' => 'Orange CI',
            'account' => '+225 07 07 •• •• 56',
            'worker_method' => 'Wave',
            'worker_account' => '+225 07 07 •• •• 56',
            'calc_status' => 'Validé',
            'pay_status' => 'Réussi',
            'payout_status' => 'À préparer',
            'reconciliation' => 'Rapproché automatiquement',
            'validated_by' => 'A. Koné',
            'validated_at' => '2026-09-20',
            'paid_at' => '2026-09-21',
            'external_reference' => 'ORA-'.fake()->unique()->numberBetween(1000000, 9999999),
            'attempts' => 1,
            'rule_version' => 'COM-2026-v3',
            'responsible' => 'N. Yao',
            'attendance_refs' => [],
            'documents' => [],
            'reported_anomalies' => [],
        ];
    }

    public function unpaid(): static
    {
        return $this->state(['pay_status' => 'En attente', 'paid_at' => null, 'external_reference' => null, 'payout_status' => 'Non disponible', 'reconciliation' => 'Non rapproché']);
    }
}
