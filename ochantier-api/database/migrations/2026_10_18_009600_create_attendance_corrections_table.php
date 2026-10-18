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
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('attendance_event_id')->nullable()->constrained('attendance_events');
            $table->foreignUuid('work_day_id')->nullable()->constrained('work_days');
            $table->string('requested_by_type', 20);
            $table->uuid('requested_by_id');
            $table->text('reason');
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->string('status', 10)->default('demandee');
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Correction motivée : l\'ancienne valeur est conservée');
        });

        DB::statement('ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_status_check CHECK (status IN (\'demandee\',\'approuvee\',\'refusee\'))');
        DB::statement('ALTER TABLE attendance_corrections ADD CONSTRAINT attendance_corrections_check1 CHECK (attendance_event_id IS NOT NULL OR work_day_id IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
