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
        Schema::create('missions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('pricing_snapshot_id')->constrained('pricing_snapshots');
            $table->string('status', 20)->default('en_attente_acompte');
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->integer('planned_days');
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->foreignUuid('terms_consent_id')->nullable()->comment('acceptation des conditions financières')->constrained('consents');
            $table->timestampTz('deposit_paid_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('activated_by_admin_id')->nullable()->constrained('admins');
            $table->integer('validated_days')->default(0)->comment('cache');
            $table->decimal('calendar_progress', 5, 2)->default(0)->comment('jours validés / jours prévus × 100');
            $table->decimal('business_progress', 5, 2)->nullable()->comment('déclaré par le promoteur');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by_admin_id')->nullable()->constrained('admins');
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['site_id', 'status'], 'ix_missions_site');
            $table->comment('Mission = engagement confirmé, un par besoin, après paiement de l\'acompte');
        });

        DB::statement('ALTER TABLE missions ADD CONSTRAINT missions_status_check CHECK (status IN (\'en_attente_acompte\',\'confirmee\',\'en_cours\',\'suspendue\',\'terminee\',\'annulee\'))');
        DB::statement('ALTER TABLE missions ADD CONSTRAINT missions_planned_days_check CHECK (planned_days > 0)');
        DB::statement('ALTER TABLE missions ADD CONSTRAINT missions_check1 CHECK (planned_end_date >= planned_start_date)');
        DB::statement('ALTER TABLE missions ADD CONSTRAINT missions_check2 CHECK (status IN (\'en_attente_acompte\',\'annulee\') OR deposit_paid_at IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_missions_need_active ON missions (need_id) WHERE status <> \'annulee\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
