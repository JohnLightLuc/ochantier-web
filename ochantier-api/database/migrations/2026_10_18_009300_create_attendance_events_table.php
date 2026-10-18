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
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('assignment_id')->constrained('assignments');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->date('work_date');
            $table->string('event_type', 10);
            $table->timestampTz('recorded_at')->useCurrent()->comment('heure serveur de référence');
            $table->timestampTz('device_recorded_at')->nullable()->comment('heure du terminal (diagnostic)');
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->foreignUuid('photo_media_id')->constrained('media_files');
            $table->foreignUuid('signature_media_id')->constrained('media_files');
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->decimal('location_accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_to_site_m', 10, 2)->nullable();
            $table->boolean('out_of_zone')->default(false);
            $table->string('device_id', 100)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('status', 10)->default('soumis');
            $table->uuid('superseded_by_id')->nullable();
            $table->uuid('client_uuid')->unique()->comment('idempotence hors connexion');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['site_id', 'work_date'], 'ix_attendance_events_site_day');
            $table->comment('Événement de pointage (arrivée ou départ) saisi par le promoteur ou un responsable autorisé');
        });

        Schema::table('attendance_events', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('attendance_events');
        });

        DB::statement('ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_event_type_check CHECK (event_type IN (\'arrivee\',\'depart\'))');
        DB::statement('ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_status_check CHECK (status IN (\'soumis\',\'valide\',\'rejete\',\'corrige\',\'annule\'))');
        DB::statement('CREATE UNIQUE INDEX uq_attendance_events_day ON attendance_events (assignment_id, work_date, event_type) WHERE status IN (\'soumis\',\'valide\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
