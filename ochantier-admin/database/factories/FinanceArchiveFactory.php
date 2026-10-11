<?php

namespace Database\Factories;

use App\Enums\WorkerStatus;
use App\Models\FinanceArchive;
use App\Models\Site;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceArchive>
 */
class FinanceArchiveFactory extends Factory
{
    /**
     * Commission archivée : 5 jours à 10 000 FCFA (Y 8 %, Z 10 %), payée par Orange Money, reversée et rapprochée.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1000, 9999);

        return [
            'reference' => "COM-A2603-{$n}",
            'operation_reference' => "OP-2026-A{$n}",
            'external_reference' => 'ORA-'.fake()->unique()->numberBetween(5100000, 5199999),
            'site_id' => Site::factory(),
            'worker_id' => Worker::factory()->state(['status' => WorkerStatus::Validated, 'daily_rate' => 10000]),
            'type' => 'Paiement hebdomadaire',
            'is_test' => false,
            'service_date' => '2026-03-14',
            'initiated_at' => '2026-03-16',
            'paid_at' => '2026-03-16',
            'collected_at' => '2026-03-16',
            'payout_at' => '2026-03-17',
            'reconciled_at' => '2026-03-17',
            'days' => 5,
            'daily_rate' => 10000,
            'promoter_rate' => 8,
            'worker_rate' => 10,
            'method' => 'Orange Money',
            'provider' => 'Orange CI',
            'worker_method' => 'Wave',
            'pay_status' => 'Réussi',
            'payout_status' => 'Réussi',
            'commission_status' => 'Encaissée',
            'reconciliation' => 'Rapproché automatiquement',
            'attempts' => 1,
            'message' => null,
            'refunds' => [],
            'rule_version' => 'COM-2026-v3',
            'responsible' => 'A. Koné',
        ];
    }

    /**
     * Paiement promoteur en échec (créance non encaissée).
     */
    public function failed(): static
    {
        return $this->state(['pay_status' => 'Échoué', 'payout_status' => 'Non disponible', 'commission_status' => 'À encaisser', 'reconciliation' => 'Non rapproché', 'paid_at' => null, 'collected_at' => null, 'payout_at' => null, 'reconciled_at' => null, 'attempts' => 3]);
    }
}
