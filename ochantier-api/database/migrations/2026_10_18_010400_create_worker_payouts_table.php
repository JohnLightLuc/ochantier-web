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
        Schema::create('worker_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('payment_schedule_id')->nullable()->constrained('payment_schedules');
            $table->foreignUuid('batch_id')->nullable()->constrained('worker_payout_batches');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->bigInteger('gross_amount');
            $table->bigInteger('commission_amount')->default(0);
            $table->bigInteger('deduction_amount')->default(0);
            $table->bigInteger('bonus_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('status', 15)->default('a_calculer');
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['worker_id', 'status'], 'ix_worker_payouts_worker');
            $table->comment('Reversement à un ouvrier (ordre transmis au prestataire, double validation)');
        });

        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_gross_amount_check CHECK (gross_amount >= 0)');
        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_bonus_amount_check CHECK (bonus_amount >= 0)');
        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_net_amount_check CHECK (net_amount >= 0)');
        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_status_check CHECK (status IN (\'a_calculer\',\'provisionne\',\'autorise\',\'envoye\',\'confirme\',\'rejete\',\'annule\'))');
        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_check1 CHECK (net_amount = gross_amount - commission_amount - deduction_amount + bonus_amount)');
        DB::statement('ALTER TABLE worker_payouts ADD CONSTRAINT worker_payouts_check2 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_payouts');
    }
};
