<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clés étrangères différées : leurs tables cibles sont créées après les tables qui les portent.
     *
     * @var array<string, array<string, string>>
     */
    private array $foreignKeys = [
        'subscriptions' => ['payment_method_id' => 'payment_methods'],
        'prospects' => ['converted_worker_id' => 'workers'],
        'matching_runs' => ['replacement_id' => 'replacements'],
        'work_days' => ['payment_schedule_id' => 'payment_schedules'],
        'refunds' => ['original_transaction_id' => 'payment_transactions', 'dispute_id' => 'disputes'],
        'payment_transactions' => ['commercial_payout_id' => 'commercial_payouts'],
        'payment_transaction_status_histories' => ['webhook_event_id' => 'payment_webhook_events'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->foreignKeys as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column => $target) {
                    $table->foreign($column)->references('id')->on($target);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->foreignKeys as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach (array_keys($columns) as $column) {
                    $table->dropForeign([$column]);
                }
            });
        }
    }
};
