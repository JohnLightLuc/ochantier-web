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
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('provider', 40);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('status', 10)->default('en_cours');
            $table->jsonb('summary')->default('{}');
            $table->foreignUuid('run_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::statement('ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN (\'en_cours\',\'termine\',\'echoue\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
