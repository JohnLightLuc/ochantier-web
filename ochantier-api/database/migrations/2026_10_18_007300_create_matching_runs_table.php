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
        Schema::create('matching_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('cycle_id')->nullable()->constrained('matching_cycles');
            $table->foreignUuid('weight_set_id')->constrained('matching_weight_sets');
            $table->string('run_type', 15)->default('initial');
            $table->uuid('replacement_id')->nullable()->comment('FK ajoutée après la création de replacements');
            $table->jsonb('filters')->default('{}');
            $table->foreignUuid('triggered_by_admin_id')->nullable()->comment('NULL si lancement automatique')->constrained('admins');
            $table->integer('candidate_count')->default(0);
            $table->timestampTz('executed_at')->useCurrent();
        });

        DB::statement('ALTER TABLE matching_runs ADD CONSTRAINT matching_runs_run_type_check CHECK (run_type IN (\'initial\',\'relance\',\'remplacement\'))');
        DB::statement('CREATE INDEX ix_matching_runs_need ON matching_runs (need_id, executed_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_runs');
    }
};
