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
        Schema::create('report_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('schedule_id')->nullable()->constrained('report_schedules');
            $table->string('report_kind', 40);
            $table->string('name', 160);
            $table->string('format', 5);
            $table->jsonb('filters')->default('{}');
            $table->integer('row_count')->nullable();
            $table->foreignUuid('media_file_id')->nullable()->constrained('media_files');
            $table->foreignUuid('generated_by_admin_id')->nullable()->comment('NULL si planifié')->constrained('admins');
            $table->timestampTz('generated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE report_runs ADD CONSTRAINT report_runs_format_check CHECK (format IN (\'csv\',\'xlsx\',\'pdf\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
