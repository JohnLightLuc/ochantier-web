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
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('selection_id')->nullable()->constrained('selections');
            $table->foreignUuid('team_id')->nullable()->constrained('teams');
            $table->string('role_label', 120)->nullable();
            $table->boolean('is_team_lead')->default(false);
            $table->foreignUuid('commercial_id')->nullable()->comment('commercial rattaché à cette date')->constrained('commercial_profiles');
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->rawColumn('period', 'daterange')->nullable()->storedAs('daterange(planned_start_date, planned_end_date, \'[]\')');
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();
            $table->bigInteger('promoter_daily_rate')->nullable()->comment('non contractuel : prix ouvrier + Z');
            $table->bigInteger('worker_net_daily_rate')->nullable()->comment('non contractuel : prix déclaré par l\'ouvrier');
            $table->bigInteger('salary_amount')->nullable()->comment('contractuel : salaire de l\'ouvrier');
            $table->string('salary_period', 10)->nullable();
            $table->decimal('z_rate', 7, 4);
            $table->string('status', 15)->default('confirmee');
            $table->uuid('replaces_assignment_id')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['mission_id', 'status'], 'ix_assignments_mission');
            $table->index(['worker_id', 'status'], 'ix_assignments_worker');
            $table->comment('Affectation d\'un ouvrier à une mission (conditions financières figées)');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreign('replaces_assignment_id')->references('id')->on('assignments');
        });

        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_salary_period_check CHECK (salary_period IN (\'jour\',\'semaine\',\'mois\',\'forfait\'))');
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_status_check CHECK (status IN (\'confirmee\',\'active\',\'terminee\',\'remplacee\',\'annulee\'))');
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_check1 CHECK (planned_end_date >= planned_start_date)');
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_check2 CHECK (worker_net_daily_rate IS NOT NULL OR salary_amount IS NOT NULL)');
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_overlap_excl EXCLUDE USING gist (worker_id WITH =, period WITH &&) WHERE (status IN (\'confirmee\',\'active\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
