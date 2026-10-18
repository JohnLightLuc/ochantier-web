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
        Schema::create('unavailability_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('assignment_id')->constrained('assignments');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('phase', 15);
            $table->string('declared_by_type', 15);
            $table->uuid('declared_by_id');
            $table->timestampTz('effective_at');
            $table->date('last_worked_date')->nullable();
            $table->foreignUuid('reason_id')->nullable()->constrained('reason_codes');
            $table->text('reason_comment')->nullable();
            $table->integer('estimated_duration_days')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->foreignUuid('proof_media_id')->nullable()->constrained('media_files');
            $table->string('status', 25)->default('declaree');
            $table->text('cancellation_reason')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'is_urgent'], 'ix_unavailability_requests_open');
        });

        DB::statement('ALTER TABLE unavailability_requests ADD CONSTRAINT unavailability_requests_phase_check CHECK (phase IN (\'avant_demarrage\',\'en_execution\'))');
        DB::statement('ALTER TABLE unavailability_requests ADD CONSTRAINT unavailability_requests_declared_by_type_check CHECK (declared_by_type IN (\'promoteur\',\'commercial\',\'admin\',\'ouvrier\'))');
        DB::statement('ALTER TABLE unavailability_requests ADD CONSTRAINT unavailability_requests_estimated_duration_days_check CHECK (estimated_duration_days > 0)');
        DB::statement('ALTER TABLE unavailability_requests ADD CONSTRAINT unavailability_requests_status_check CHECK (status IN (\'declaree\',\'en_analyse\',\'remplacement_recherche\',\'remplacant_propose\', \'validee\',\'refusee\',\'cloturee\',\'annulee\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unavailability_requests');
    }
};
