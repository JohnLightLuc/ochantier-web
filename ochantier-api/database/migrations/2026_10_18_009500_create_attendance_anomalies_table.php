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
        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('assignment_id')->constrained('assignments');
            $table->foreignUuid('work_day_id')->nullable()->constrained('work_days');
            $table->string('anomaly_type', 25);
            $table->string('detected_by', 10);
            $table->string('reported_by_type', 20)->nullable();
            $table->uuid('reported_by_id')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->text('comment')->nullable();
            $table->foreignUuid('media_file_id')->nullable()->constrained('media_files');
            $table->string('status', 15)->default('ouverte');
            $table->text('resolution')->nullable();
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE attendance_anomalies ADD CONSTRAINT attendance_anomalies_anomaly_type_check CHECK (anomaly_type IN (\'absence\',\'retard\',\'depart_anticipe\',\'doublon\',\'hors_zone\', \'preuve_manquante\',\'incoherence_horaire\',\'photo_reutilisee\'))');
        DB::statement('ALTER TABLE attendance_anomalies ADD CONSTRAINT attendance_anomalies_detected_by_check CHECK (detected_by IN (\'systeme\',\'promoteur\',\'admin\'))');
        DB::statement('ALTER TABLE attendance_anomalies ADD CONSTRAINT attendance_anomalies_status_check CHECK (status IN (\'ouverte\',\'en_traitement\',\'resolue\',\'rejetee\'))');
        DB::statement('CREATE INDEX ix_attendance_anomalies_open ON attendance_anomalies (status) WHERE status IN (\'ouverte\',\'en_traitement\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_anomalies');
    }
};
