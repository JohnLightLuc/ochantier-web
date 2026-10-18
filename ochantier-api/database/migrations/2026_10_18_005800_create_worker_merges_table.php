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
        Schema::create('worker_merges', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('source_worker_id')->constrained('workers');
            $table->foreignUuid('target_worker_id')->constrained('workers');
            $table->jsonb('field_choices');
            $table->foreignUuid('merged_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->constrained('admins');
            $table->timestampTz('merged_at')->useCurrent();
        });

        DB::statement('ALTER TABLE worker_merges ADD CONSTRAINT worker_merges_check1 CHECK (source_worker_id <> target_worker_id)');
        DB::statement('ALTER TABLE worker_merges ADD CONSTRAINT worker_merges_check2 CHECK (merged_by_admin_id <> approved_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_merges');
    }
};
