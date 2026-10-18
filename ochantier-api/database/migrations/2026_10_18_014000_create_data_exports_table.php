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
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('admin_id')->constrained('admins');
            $table->string('module', 40);
            $table->jsonb('filters')->default('{}');
            $table->rawColumn('columns', 'text[]');
            $table->string('format', 5);
            $table->integer('row_count')->nullable();
            $table->foreignUuid('media_file_id')->nullable()->constrained('media_files');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE data_exports ADD CONSTRAINT data_exports_format_check CHECK (format IN (\'csv\',\'xlsx\',\'pdf\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
