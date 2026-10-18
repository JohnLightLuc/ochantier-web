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
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->string('schedule_type', 15);
            $table->integer('sequence');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignUuid('milestone_id')->nullable()->constrained('offer_milestones');
            $table->decimal('threshold_percent', 5, 2)->nullable()->comment('seuil R atteint (progression calendaire)');
            $table->bigInteger('workers_amount')->default(0)->comment('coût ouvriers de la période');
            $table->bigInteger('promoter_commission_amount')->default(0)->comment('Y');
            $table->bigInteger('deposit_deduction_amount')->default(0)->comment('part de l\'acompte imputée');
            $table->bigInteger('adjustment_amount')->default(0)->comment('régularisation (+/−)');
            $table->bigInteger('amount_due');
            $table->bigInteger('amount_paid')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('prevue');
            $table->foreignUuid('pricing_snapshot_id')->constrained('pricing_snapshots');
            $table->jsonb('calculation_details')->default('{}');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['mission_id', 'schedule_type', 'sequence']);
            $table->index(['status', 'due_date'], 'ix_payment_schedules_due');
            $table->comment('Échéance due par le promoteur ; l\'acompte X est déduit des échéances suivantes');
        });

        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_schedule_type_check CHECK (schedule_type IN (\'acompte\',\'journaliere\',\'hebdomadaire\',\'mensuelle\',\'jalon\', \'seuil_r\',\'solde\',\'regularisation\'))');
        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_amount_due_check CHECK (amount_due >= 0)');
        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_amount_paid_check CHECK (amount_paid >= 0)');
        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_status_check CHECK (status IN (\'prevue\',\'exigible\',\'partiellement_payee\',\'payee\',\'en_retard\', \'contestee\',\'annulee\',\'remboursee\'))');
        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_check1 CHECK (schedule_type <> \'seuil_r\' OR threshold_percent IS NOT NULL)');
        DB::statement('ALTER TABLE payment_schedules ADD CONSTRAINT payment_schedules_check2 CHECK (schedule_type <> \'jalon\' OR milestone_id IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_payment_schedules_threshold ON payment_schedules (mission_id, threshold_percent) WHERE schedule_type = \'seuil_r\' AND status <> \'annulee\'');
        DB::statement('CREATE UNIQUE INDEX uq_payment_schedules_deposit ON payment_schedules (mission_id) WHERE schedule_type = \'acompte\' AND status NOT IN (\'annulee\',\'remboursee\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};
