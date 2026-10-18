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
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('report_kind', 40)->comment('operationnel, financier, commerciaux, qualite…');
            $table->string('name', 160);
            $table->string('frequency', 12);
            $table->timestampTz('next_run_at');
            $table->jsonb('recipients')->default('[]');
            $table->string('format', 5);
            $table->jsonb('filters')->default('{}');
            $table->string('status', 10)->default('actif');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_frequency_check CHECK (frequency IN (\'quotidien\',\'hebdomadaire\',\'mensuel\'))');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN (\'csv\',\'xlsx\',\'pdf\'))');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_status_check CHECK (status IN (\'actif\',\'suspendu\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
