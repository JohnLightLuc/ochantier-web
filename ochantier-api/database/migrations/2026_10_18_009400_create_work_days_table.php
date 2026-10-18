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
        Schema::create('work_days', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('assignment_id')->constrained('assignments');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->date('work_date');
            $table->foreignUuid('arrival_event_id')->nullable()->constrained('attendance_events');
            $table->foreignUuid('departure_event_id')->nullable()->constrained('attendance_events');
            $table->integer('worked_minutes')->nullable();
            $table->string('status', 12)->default('en_cours');
            $table->text('observation')->nullable();
            $table->foreignUuid('validated_by_user_id')->nullable()->comment('validation unique par le promoteur')->constrained('users');
            $table->timestampTz('validated_at')->nullable();
            $table->boolean('is_locked')->default(false)->comment('verrouillée dès qu\'elle est facturée');
            $table->uuid('payment_schedule_id')->nullable()->comment('FK ajoutée après la création de payment_schedules');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['assignment_id', 'work_date']);
            $table->index(['mission_id', 'status'], 'ix_work_days_mission');
            $table->comment('Journée travaillée : unité payable');
        });

        DB::statement('ALTER TABLE work_days ADD CONSTRAINT work_days_worked_minutes_check CHECK (worked_minutes >= 0)');
        DB::statement('ALTER TABLE work_days ADD CONSTRAINT work_days_status_check CHECK (status IN (\'en_cours\',\'a_valider\',\'valide\',\'rejete\',\'absent\',\'annule\'))');
        DB::statement('ALTER TABLE work_days ADD CONSTRAINT work_days_check1 CHECK (status <> \'valide\' OR (arrival_event_id IS NOT NULL AND departure_event_id IS NOT NULL AND validated_by_user_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_days');
    }
};
