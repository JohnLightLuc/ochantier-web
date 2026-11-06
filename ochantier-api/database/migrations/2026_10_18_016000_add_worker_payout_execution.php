<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table) {
            $table->foreignUuid('worker_payout_id')->nullable()->comment('reversement qui paie cette journée')->constrained('worker_payouts');
            $table->index('worker_payout_id', 'ix_work_days_worker_payout');
        });

        foreach (['worker_payout_reference_seq' => 'worker_payouts.reference', 'worker_payout_batch_reference_seq' => 'worker_payout_batches.reference'] as $sequence => $column) {
            DB::statement("CREATE SEQUENCE {$sequence} START 1 OWNED BY {$column}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS worker_payout_reference_seq');
        DB::statement('DROP SEQUENCE IF EXISTS worker_payout_batch_reference_seq');

        Schema::table('work_days', function (Blueprint $table) {
            $table->dropIndex('ix_work_days_worker_payout');
            $table->dropConstrainedForeignId('worker_payout_id');
        });
    }
};
